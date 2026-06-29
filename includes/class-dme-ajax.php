<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAXエンドポイント。
 */
class DME_Ajax
{
    /**
     * 初期化。
     *
     * @return void
     */
    public static function init()
    {
        add_action('wp_ajax_dme_calculate', [__CLASS__, 'calculate']);
        add_action('wp_ajax_nopriv_dme_calculate', [__CLASS__, 'calculate']);

        add_action('wp_ajax_dme_send_mail', [__CLASS__, 'send_mail']);
        add_action('wp_ajax_nopriv_dme_send_mail', [__CLASS__, 'send_mail']);
    }

    /**
     * 見積計算。
     *
     * @return void
     */
    public static function calculate()
    {
        self::verify_nonce();

        $payload = self::get_json_payload();
        $catalog = DME_Sheets::build_front_catalog();
        $estimate = DME_Pricing::calculate($payload, $catalog);

        wp_send_json_success($estimate);
    }

    /**
     * メール送信。
     *
     * @return void
     */
    public static function send_mail()
    {
        self::verify_nonce();

        $payload = self::get_json_payload();
        $catalog = DME_Sheets::build_front_catalog();
        $estimate = DME_Pricing::calculate($payload, $catalog);

        if (empty($estimate['is_estimatable'])) {
            wp_send_json_error([
                'message' => '見積不可のため送信できません。',
                'errors' => $estimate['errors'],
            ], 400);
        }

        $contact = isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : [];
        $email = isset($contact['email']) ? sanitize_email($contact['email']) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'メールアドレスを正しく入力してください。'], 400);
        }

        $to = DME_Admin::get_recipient_email();
        $subject = '【DM見積依頼】' . (!empty($contact['company']) ? sanitize_text_field($contact['company']) : '会社名未入力');

        $body = self::build_mail_body($payload, $estimate);
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $email,
        ];

        $ok = wp_mail($to, $subject, $body, $headers);

        if (!$ok) {
            wp_send_json_error(['message' => 'メール送信に失敗しました。時間を置いて再試行してください。'], 500);
        }

        wp_send_json_success(['message' => '送信完了しました。']);
    }

    /**
     * メール本文生成。
     *
     * @param array $payload 入力値。
     * @param array $estimate 見積結果。
     * @return string
     */
    private static function build_mail_body($payload, $estimate)
    {
        $contact = isset($payload['contact']) ? $payload['contact'] : [];

        $lines = [];
        $lines[] = 'DM見積依頼を受信しました。';
        $lines[] = '----------------------------------------';
        $lines[] = '会社名: ' . self::s($contact, 'company');
        $lines[] = '氏名: ' . self::s($contact, 'name');
        $lines[] = '住所: ' . self::s($contact, 'address');
        $lines[] = '電話: ' . self::s($contact, 'phone');
        $lines[] = 'メール: ' . self::s($contact, 'email');
        $lines[] = '希望日: ' . self::s($contact, 'desired_date');
        $lines[] = '備考: ' . self::s($contact, 'note');
        if (isset($payload['replyMode']) && $payload['replyMode'] === 'receiver') {
            $receiver_reply_summary = self::get_receiver_reply_summary($estimate);
            if ($receiver_reply_summary !== null) {
                $lines[] = '受取人払い想定返信率: ' . $receiver_reply_summary['response_rate'] . '％';
                $lines[] = '受取人払い想定返信数: ' . $receiver_reply_summary['estimated_response_count'] . '通';
            }
        }
        $lines[] = '';
        $lines[] = '【見積明細】';

        foreach ($estimate['items'] as $item) {
            $lines[] = sprintf(
                '- %s | 単価:%s | 数量:%d | 小計:%s | 備考:%s',
                $item['label'],
                number_format((int) $item['unit_price']) . '円',
                (int) $item['quantity'],
                number_format((int) $item['subtotal']) . '円',
                (string) $item['note']
            );
        }

        $lines[] = '----------------------------------------';
        $lines[] = '合計: ' . number_format((int) $estimate['total']) . '円';

        return implode("\n", $lines);
    }

    private static function s($arr, $key)
    {
        return isset($arr[$key]) ? sanitize_text_field((string) $arr[$key]) : '';
    }

    private static function get_receiver_reply_summary($estimate)
    {
        if (empty($estimate['items']) || !is_array($estimate['items'])) {
            return null;
        }

        foreach ($estimate['items'] as $item) {
            if (!isset($item['label']) || $item['label'] !== '受取人払い') {
                continue;
            }

            $note = isset($item['note']) ? (string) $item['note'] : '';
            if (preg_match('/想定返信率([0-9]+)％・想定返信数([0-9]+)通/u', $note, $matches)) {
                return [
                    'response_rate' => (int) $matches[1],
                    'estimated_response_count' => (int) $matches[2],
                ];
            }
        }

        return null;
    }

    /**
     * JSON入力取得。
     *
     * @return array
     */
    private static function get_json_payload()
    {
        $raw = file_get_contents('php://input');
        $json = json_decode((string) $raw, true);
        return is_array($json) ? $json : [];
    }

    /**
     * nonce検証。
     *
     * @return void
     */
    private static function verify_nonce()
    {
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'dme_nonce')) {
            wp_send_json_error(['message' => '不正なリクエストです。'], 403);
        }
    }
}
