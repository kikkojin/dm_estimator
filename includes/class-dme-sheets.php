<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Googleスプレッドシートから公開データを取得・正規化するクラス。
 */
class DME_Sheets
{
    const CACHE_KEY = 'dme_sheet_books_v2';
    const CACHE_KEY_PREFIX = 'dme_sheet_books_';

    /**
     * APIキー未設定警告の重複出力抑止。
     *
     * @var bool
     */
    private static $missing_api_key_warned = false;

    /**
     * 参照するスプレッドシートID一覧。
     * キーはシステム内の種別キー。
     */
    const SHEET_BOOKS = [
        'a4_offset' => '1FYBMdP2KGsnwvFHrghbnag8VQiY7Cm88S34av_YR2y0',
        'envelope_print' => '11bYdJlCXIxFiiYv7ZvQ7G0hsf1__1rexb4VnbtHxtgk',
        'booklet' => '1lJH6a_y54qtyAhk0a5pfESa8esYDj7yYed71hB5EzgA',
        'leaflet' => '1mmAl1kyBUPM_FgozIeIU-bfqBkalkLBVQqINNe2APIA',
        'pressure_dm' => '1aVMLpbXPjfay6eNWSilua5vwui26NeA0vafJVoUVMR8',
        'postcard' => '1Lvc1XwdrCX1obxPsHiGfhwegK4iAekPzXAcnnW1BSVY',
        'dm_work' => '1LwT3hGk5f1T-FFLlv7mxfJkHS2mxVTN-stW__5mxOn4',
        'paper_weight' => '1aGhlED9eQy_QdDk5pMFKs7GR2Lz7yJABzZCGi49Bnjw',
        'postage' => '18eAZhTocuD45Rq2LoL4cRUnJVKRLq6Bl0WRE3AAllTM',
    ];

    /**
     * フロント向けカタログを返す。
     *
     * @return array
     */
    public static function build_front_catalog()
    {
        $all = self::get_all_books();

        $print_books = ['a4_offset', 'envelope_print', 'booklet', 'leaflet', 'pressure_dm', 'postcard'];
        $print_catalog = [];

        foreach ($print_books as $book_key) {
            if (empty($all[$book_key]['sheets'])) {
                continue;
            }
            foreach ($all[$book_key]['sheets'] as $sheet_data) {
                $print_catalog[] = [
                    'bookKey' => $book_key,
                    'bookLabel' => $all[$book_key]['label'],
                    'sheetName' => $sheet_data['sheet_name'],
                    'conditions' => $sheet_data['conditions'],
                    'headers' => $sheet_data['headers'],
                    'matrix' => $sheet_data['matrix'],
                    'quantities' => $sheet_data['quantities'],
                ];
            }
        }

        $catalog = [
            'printCatalog' => $print_catalog,
            'workFees' => isset($all['dm_work']) ? $all['dm_work']['sheets'] : [],
            'paperWeights' => isset($all['paper_weight']) ? $all['paper_weight']['sheets'] : [],
            'postage' => isset($all['postage']) ? $all['postage']['sheets'] : [],
            'updatedAt' => gmdate('c'),
        ];

        $work_fee_row_count = 0;
        foreach ($catalog['workFees'] as $sheet) {
            $work_fee_row_count += !empty($sheet['rows']) && is_array($sheet['rows']) ? count($sheet['rows']) : 0;
        }
        $paper_weight_row_count = 0;
        foreach ($catalog['paperWeights'] as $sheet) {
            $paper_weight_row_count += !empty($sheet['rows']) && is_array($sheet['rows']) ? count($sheet['rows']) : 0;
        }
        $postage_row_count = 0;
        foreach ($catalog['postage'] as $sheet) {
            $postage_row_count += !empty($sheet['rows']) && is_array($sheet['rows']) ? count($sheet['rows']) : 0;
        }

        self::debug_log('価格表データ取得結果', [
            '印刷料金表' => count($catalog['printCatalog']),
            'DM作業費表シート数' => count($catalog['workFees']),
            'DM作業費行数' => $work_fee_row_count,
            '紙重量表シート数' => count($catalog['paperWeights']),
            '紙重量行数' => $paper_weight_row_count,
            '郵便料金表シート数' => count($catalog['postage']),
            '郵便料金行数' => $postage_row_count,
        ]);

        return $catalog;
    }

    /**
     * 全ブック読み込み。
     *
     * @return array
     */
    public static function get_all_books()
    {
        $disable_cache = self::is_price_cache_disabled();
        $cache_key = self::CACHE_KEY;

        if ($disable_cache) {
            self::debug_log('価格表キャッシュ無効化中のため、スプレッドシートから再取得します');
            self::clear_books_cache('価格表キャッシュ無効化中');
        } else {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                self::debug_log('価格表キャッシュを使用しました');
                return $cached;
            }
            self::debug_log('価格表キャッシュが見つからないため、スプレッドシートから再取得します');
        }

        $output = [];
        foreach (self::SHEET_BOOKS as $book_key => $spreadsheet_id) {
            $output[$book_key] = [
                'label' => $book_key,
                'sheets' => self::load_book_sheets($spreadsheet_id, $book_key),
            ];
        }

        if ($disable_cache) {
            return $output;
        }

        if (!self::has_any_price_data($output)) {
            self::debug_log('価格表データが空のため、キャッシュ保存しませんでした', [], 'warning');
            return $output;
        }

        set_transient($cache_key, $output, 30 * MINUTE_IN_SECONDS);
        return $output;
    }

    /**
     * 価格表キャッシュ設定を確認。
     *
     * @return bool
     */
    private static function is_price_cache_disabled()
    {
        if (!class_exists('DME_Admin') || !method_exists('DME_Admin', 'is_price_cache_disabled')) {
            return false;
        }

        return DME_Admin::is_price_cache_disabled();
    }

    /**
     * 価格データが1件以上あるか判定。
     *
     * @param array $books 全ブック配列。
     * @return bool
     */
    private static function has_any_price_data($books)
    {
        if (!is_array($books)) {
            return false;
        }

        foreach ($books as $book) {
            if (!empty($book['sheets']) && is_array($book['sheets'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 価格表キャッシュを削除。
     *
     * @param string $reason 削除理由。
     * @return void
     */
    public static function clear_books_cache($reason = '')
    {
        global $wpdb;

        delete_transient(self::CACHE_KEY);

        $like = $wpdb->esc_like('_transient_' . self::CACHE_KEY_PREFIX) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . self::CACHE_KEY_PREFIX) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $timeout_like
            )
        );

        if (is_multisite()) {
            delete_site_transient(self::CACHE_KEY);
            $site_like = $wpdb->esc_like('_site_transient_' . self::CACHE_KEY_PREFIX) . '%';
            $site_timeout_like = $wpdb->esc_like('_site_transient_timeout_' . self::CACHE_KEY_PREFIX) . '%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
                    $site_like,
                    $site_timeout_like
                )
            );
        }

        self::debug_log('価格表キャッシュを削除しました', [
            'プレフィックス' => self::CACHE_KEY_PREFIX,
            '理由' => (string) $reason,
        ]);
    }

    /**
     * ブック内シート一覧と内容取得。
     *
     * @param string $spreadsheet_id スプレッドシートID。
     * @param string $book_key       ブックキー。
     * @return array
     */
    private static function load_book_sheets($spreadsheet_id, $book_key = '')
    {
        $sheet_names = self::get_sheet_names($spreadsheet_id);
        self::verbose_log('Resolved sheet names', [
            'book_key' => $book_key,
            'spreadsheet_id' => $spreadsheet_id,
            'sheet_names' => $sheet_names,
        ]);
        if (empty($sheet_names)) {
            self::debug_log('No sheet names discovered', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
            ], 'warning');
            return [];
        }

        $sheets = [];
        $fetched_sheet_names = [];
        $normalized_count = 0;
        foreach ($sheet_names as $sheet_name) {
            $sheet_name = trim((string) $sheet_name);
            if ($sheet_name === '') {
                continue;
            }
            $fetched_sheet_names[] = $sheet_name;

            $csv_url = sprintf(
                'https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:csv&sheet=%s',
                rawurlencode($spreadsheet_id),
                rawurlencode($sheet_name)
            );
            self::verbose_log('CSV request URL', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_name' => $sheet_name,
                'csv_url' => $csv_url,
            ]);

            $csv_res = wp_remote_get($csv_url, ['timeout' => 15]);
            if (is_wp_error($csv_res)) {
                self::debug_log('CSV request failed', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                    'error' => $csv_res->get_error_message(),
                ], 'error');
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($csv_res);
            $body = (string) wp_remote_retrieve_body($csv_res);
            self::verbose_log('CSV response', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_name' => $sheet_name,
                'csv_url' => $csv_url,
                'http_status' => $status,
                'body_head_300' => mb_substr($body, 0, 300),
            ]);

            if ($status < 200 || $status >= 300) {
                self::debug_log('CSV response status is not 2xx', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                    'http_status' => $status,
                ], 'error');
                continue;
            }

            $rows = self::parse_csv($body);
            if (count($rows) === 0) {
                self::debug_log('CSV parse produced zero rows', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                ], 'error');
                continue;
            }
            $row_blocks = self::split_row_blocks($rows);
            $total_blocks = count($row_blocks);
            self::verbose_log('CSV parse summary', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_name' => $sheet_name,
                'parse_csv_rows' => count($rows),
                'split_row_blocks_count' => $total_blocks,
            ]);
            if ($total_blocks === 0) {
                self::debug_log('CSV parse produced zero row blocks', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                ], 'error');
                continue;
            }

            $sheet_normalized_count = 0;
            foreach ($row_blocks as $block_index => $row_block) {
                $normalized = self::normalize_by_book_key($book_key, $sheet_name, $row_block);
                self::verbose_log('Normalization result', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                    'block_index' => $block_index,
                    'normalized_is_valid' => !empty($normalized),
                ]);
                if (empty($normalized)) {
                    continue;
                }
                $normalized_count++;
                $sheet_normalized_count++;

                if ($total_blocks > 1) {
                    $normalized['sheet_name'] = sprintf('%s [%d]', $sheet_name, $block_index + 1);
                }
                $sheets[] = $normalized;
            }

            if ($sheet_normalized_count === 0) {
                self::debug_log('Normalization result is empty for sheet', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                ], 'warning');
            }
        }

        self::verbose_log('Book load summary', [
            'book_key' => $book_key,
            'spreadsheet_id' => $spreadsheet_id,
            'fetched_sheet_names' => $fetched_sheet_names,
            'normalized_valid_count' => $normalized_count,
        ]);

        return $sheets;
    }

    /**
     * スプレッドシートからシート名一覧を取得する。
     * Google Sheets API v4 で自動取得する。
     *
     * @param string $spreadsheet_id スプレッドシートID。
     * @return array
     */
    private static function get_sheet_names($spreadsheet_id)
    {
        $api_key = self::get_google_api_key();
        if ($api_key === '') {
            self::warn_missing_api_key([
                'spreadsheet_id' => $spreadsheet_id,
            ]);
            return [];
        }

        $v4_url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s?fields=sheets.properties.title&key=%s',
            rawurlencode($spreadsheet_id),
            rawurlencode($api_key)
        );
        self::verbose_log('V4 sheet-list request URL', [
            'spreadsheet_id' => $spreadsheet_id,
            'url' => self::mask_sensitive_url($v4_url),
        ]);

        $res = wp_remote_get($v4_url, ['timeout' => 15]);
        if (is_wp_error($res)) {
            self::debug_log('V4 sheet-list request failed', [
                'spreadsheet_id' => $spreadsheet_id,
                'error' => $res->get_error_message(),
            ], 'error');
            return [];
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        self::verbose_log('V4 sheet-list response', [
            'spreadsheet_id' => $spreadsheet_id,
            'http_status' => $status,
            'body_head_300' => mb_substr($body, 0, 300),
        ]);

        if ($status < 200 || $status >= 300) {
            self::debug_log('V4 sheet-list response status is not 2xx', [
                'spreadsheet_id' => $spreadsheet_id,
                'http_status' => $status,
            ], 'error');
            return [];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['sheets']) || !is_array($json['sheets'])) {
            self::debug_log('V4 sheet-list response has no sheets', [
                'spreadsheet_id' => $spreadsheet_id,
            ], 'error');
            return [];
        }

        $sheet_names = [];
        foreach ($json['sheets'] as $sheet) {
            if (!empty($sheet['properties']['title'])) {
                $sheet_names[] = (string) $sheet['properties']['title'];
            }
        }

        self::verbose_log('V4 discovered sheet names', [
            'spreadsheet_id' => $spreadsheet_id,
            'sheet_names' => $sheet_names,
        ]);

        return $sheet_names;
    }

    /**
     * Google APIキーを取得。
     *
     * @return string
     */
    private static function get_google_api_key()
    {
        if (defined('DME_GOOGLE_API_KEY') && DME_GOOGLE_API_KEY) {
            return (string) DME_GOOGLE_API_KEY;
        }

        if (class_exists('DME_Admin')) {
            return DME_Admin::get_google_api_key();
        }

        return '';
    }

    /**
     * APIキー未設定警告を1回だけ出力。
     *
     * @param array $context 補足情報。
     * @return void
     */
    private static function warn_missing_api_key($context = [])
    {
        if (self::$missing_api_key_warned) {
            return;
        }
        self::$missing_api_key_warned = true;

        self::debug_log('Google Sheets API Key が未設定です。設定 > DM Estimator で設定してください。', $context, 'error');
    }

    /**
     * debug.log 用の出力ヘルパー。
     *
     * @param string $message メッセージ。
     * @param array  $context 補足情報。
     * @param string $level   ログレベル。
     * @return void
     */
    private static function debug_log($message, $context = [], $level = 'info')
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }
        if ($level === 'verbose' && !self::is_verbose_log_enabled()) {
            return;
        }

        $context = self::sanitize_log_context($context);
        $prefix = '[DME_Sheets][' . self::localize_log_level($level) . '] ';
        $line = $prefix . self::localize_log_message($message);
        if (!empty($context)) {
            $json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json) && $json !== '') {
                $line .= ' ' . $json;
            }
        }

        error_log($line);
    }

    /**
     * 詳細ログ専用ヘルパー。
     *
     * @param string $message メッセージ。
     * @param array  $context 補足情報。
     * @return void
     */
    private static function verbose_log($message, $context = [])
    {
        self::debug_log($message, $context, 'verbose');
    }

    /**
     * ログレベルを日本語表記へ変換。
     *
     * @param string $level ログレベル。
     * @return string
     */
    private static function localize_log_level($level)
    {
        $map = [
            'info' => '情報',
            'warning' => '警告',
            'error' => 'エラー',
            'debug' => '詳細',
            'verbose' => '詳細',
        ];
        $key = strtolower((string) $level);

        return isset($map[$key]) ? $map[$key] : strtoupper((string) $level);
    }

    /**
     * 既存英語ログメッセージを日本語へ変換。
     *
     * @param string $message ログメッセージ。
     * @return string
     */
    private static function localize_log_message($message)
    {
        $map = [
            'Cache hit' => '価格表キャッシュを使用しました',
            'Cache miss; loading sheets' => '価格表キャッシュが見つからないため、スプレッドシートから再取得します',
            'Cache disabled; loading sheets' => '価格表キャッシュ無効化中のため、スプレッドシートから再取得します',
            'Cleared price cache transients' => '価格表キャッシュを削除しました',
            'build_front_catalog result summary' => '価格表データ取得結果',
            'Empty catalog; not caching' => '価格表データが空のため、キャッシュ保存しませんでした',
            'disable_price_cache is ON; bypassing transient cache' => '価格表キャッシュ無効化中のため、スプレッドシートから再取得します',
            'Fetch result was empty; skipped transient save' => '価格表データが空のため、キャッシュ保存しませんでした',
            'Resolved sheet names' => 'シート名を解決しました',
            'No sheet names discovered' => 'シート名を取得できませんでした',
            'CSV request URL' => 'CSV取得URL',
            'CSV request failed' => 'CSV取得に失敗しました',
            'CSV response' => 'CSVレスポンス',
            'CSV response status is not 2xx' => 'CSVレスポンスが異常ステータスです',
            'CSV parse produced zero rows' => 'CSV解析結果が0行でした',
            'CSV parse summary' => 'CSV解析結果サマリー',
            'CSV parse produced zero row blocks' => 'CSV解析結果の有効ブロックが0件でした',
            'Normalization result' => '正規化結果',
            'Normalization result is empty for sheet' => 'シートの正規化結果が空でした',
            'Book load summary' => 'ブック読み込みサマリー',
            'V4 sheet-list request URL' => 'V4シート一覧取得URL',
            'V4 sheet-list request failed' => 'V4シート一覧取得に失敗しました',
            'V4 sheet-list response' => 'V4シート一覧レスポンス',
            'V4 sheet-list response status is not 2xx' => 'V4シート一覧レスポンスが異常ステータスです',
            'V4 sheet-list response has no sheets' => 'V4シート一覧レスポンスにシート情報がありません',
            'V4 discovered sheet names' => 'V4で検出したシート名',
        ];

        return isset($map[$message]) ? $map[$message] : (string) $message;
    }

    /**
     * 詳細ログを有効化しているか判定。
     *
     * @return bool
     */
    private static function is_verbose_log_enabled()
    {
        if (!class_exists('DME_Admin') || !method_exists('DME_Admin', 'is_verbose_log_enabled')) {
            return false;
        }

        return DME_Admin::is_verbose_log_enabled();
    }

    /**
     * ログ文脈内の機密情報をマスクする。
     *
     * @param mixed $context ログ文脈。
     * @return mixed
     */
    private static function sanitize_log_context($context)
    {
        if (is_array($context)) {
            $sanitized = [];
            foreach ($context as $key => $value) {
                if (is_string($value) && stripos((string) $key, 'url') !== false) {
                    $sanitized[$key] = self::mask_sensitive_url($value);
                    continue;
                }
                if (is_string($key) && in_array(strtolower($key), ['api_key', 'google_api_key', 'key'], true)) {
                    $sanitized[$key] = '***';
                    continue;
                }
                $sanitized[$key] = self::sanitize_log_context($value);
            }

            return $sanitized;
        }

        return $context;
    }

    /**
     * URL内のAPIキーをマスクする。
     *
     * @param string $url URL。
     * @return string
     */
    private static function mask_sensitive_url($url)
    {
        if (!is_string($url) || $url === '') {
            return (string) $url;
        }

        return (string) preg_replace('/([?&]key=)[^&]*/i', '$1***', $url);
    }

    /**
     * CSVを配列化。
     *
     * @param string $csv CSV文字列。
     * @return array
     */
    private static function parse_csv($csv)
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', (string) $csv);
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $rows[] = [];
                continue;
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    /**
     * 空行で分割した行ブロックを返す。
     *
     * @param array $rows CSV行配列。
     * @return array
     */
    private static function split_row_blocks($rows)
    {
        $blocks = [];
        $current = [];

        foreach ($rows as $row) {
            $is_empty = true;
            if (is_array($row)) {
                foreach ($row as $cell) {
                    if (trim((string) $cell) !== '') {
                        $is_empty = false;
                        break;
                    }
                }
            }

            if ($is_empty) {
                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }

            $current[] = $row;
        }

        if (!empty($current)) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * 価格表を正規化。
     * 1行目: 見出し（1列目は部数ラベル想定）
     * 2行目以降: 1列目に部数、2列目以降に単価/金額。
     *
     * @param string $sheet_name シート名。
     * @param array  $rows      行配列。
     * @return array
     */
    private static function normalize_price_table($sheet_name, $rows)
    {
        if (count($rows) < 2 || !isset($rows[0])) {
            return [];
        }

        $header_row = $rows[0];
        $conditions = self::parse_conditions_from_sheet_name($sheet_name);

        // 2列目以降を仕様ヘッダーとして利用（色数・ページ数など）。
        $headers = [];
        for ($i = 1; $i < count($header_row); $i++) {
            $label = trim((string) $header_row[$i]);
            if ($label !== '') {
                $headers[] = $label;
            }
        }

        $matrix = [];
        $quantities = [];

        $fallback_qty = 1;

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            if (!isset($row[0])) {
                continue;
            }

            $qty = (int) preg_replace('/[^0-9]/', '', (string) $row[0]);
            if ($qty <= 0) {
                // 作業費などは1列目が名称で、部数になっていない表がある。
                $qty = $fallback_qty;
                $fallback_qty++;
            }

            $row_prices = [];
            foreach ($headers as $index => $header_key) {
                $cell_index = $index + 1;
                $price = isset($row[$cell_index]) ? self::to_int_price($row[$cell_index]) : null;
                if ($price !== null) {
                    $row_prices[$header_key] = $price;
                }
            }

            if (empty($row_prices)) {
                continue;
            }

            while (isset($matrix[$qty])) {
                $qty++;
            }

            $quantities[] = $qty;
            $matrix[$qty] = $row_prices;
        }

        if (empty($quantities)) {
            return [];
        }

        sort($quantities, SORT_NUMERIC);

        return [
            'sheet_name' => $sheet_name,
            'parser' => 'print_price',
            'conditions' => $conditions,
            'headers' => $headers,
            'quantities' => $quantities,
            'matrix' => $matrix,
        ];
    }

    /**
     * ブックキーに応じた正規化ルーティング。
     *
     * @param string $book_key   ブックキー。
     * @param string $sheet_name シート名。
     * @param array  $rows       行配列。
     * @return array
     */
    private static function normalize_by_book_key($book_key, $sheet_name, $rows)
    {
        $print_books = ['a4_offset', 'envelope_print', 'booklet', 'leaflet', 'pressure_dm', 'postcard'];
        if (in_array($book_key, $print_books, true)) {
            return self::normalize_price_table($sheet_name, $rows);
        }

        if ($book_key === 'dm_work') {
            return self::normalize_dm_work_table($sheet_name, $rows);
        }

        if ($book_key === 'paper_weight') {
            return self::normalize_paper_weight_table($sheet_name, $rows);
        }

        if ($book_key === 'postage') {
            return self::normalize_postage_table($sheet_name, $rows);
        }

        return self::normalize_price_table($sheet_name, $rows);
    }

    /**
     * DM作業費表を正規化。
     *
     * @param string $sheet_name シート名。
     * @param array  $rows      行配列。
     * @return array
     */
    private static function normalize_dm_work_table($sheet_name, $rows)
    {
        if (count($rows) < 2) {
            return [];
        }

        $header_map = self::build_header_index_map($rows[0]);
        $data_rows = array_slice($rows, 1);
        $entries = [];
        $by_code = [];

        foreach ($data_rows as $row) {
            $work_code = self::read_cell_by_header($row, $header_map, '作業コード', 0);
            $work_name = self::read_cell_by_header($row, $header_map, '作業名', 1);
            if ($work_code === '' && $work_name === '') {
                continue;
            }

            $entry = [
                'work_code' => $work_code,
                'work_name' => $work_name,
                'billing_type' => self::read_cell_by_header($row, $header_map, '課金タイプ', 2),
                'unit_price' => self::to_int_price(self::read_cell_by_header($row, $header_map, '単価', 3)),
                'basic_fee' => self::to_int_price(self::read_cell_by_header($row, $header_map, '基本料金', 4)),
                'note' => self::read_cell_by_header($row, $header_map, '備考', 5),
            ];
            $entries[] = $entry;

            if ($work_code !== '') {
                $by_code[$work_code] = $entry;
            }
        }

        if (empty($entries)) {
            return [];
        }

        return [
            'sheet_name' => $sheet_name,
            'parser' => 'dm_work',
            'rows' => $entries,
            'by_code' => $by_code,
        ];
    }

    /**
     * 紙重量表を正規化。
     *
     * @param string $sheet_name シート名。
     * @param array  $rows      行配列。
     * @return array
     */
    private static function normalize_paper_weight_table($sheet_name, $rows)
    {
        if (count($rows) < 2) {
            return [];
        }

        $header_map = self::build_header_index_map($rows[0]);
        $data_rows = array_slice($rows, 1);
        $entries = [];

        foreach ($data_rows as $row) {
            $weight_g = self::to_float_number(self::read_cell_by_header($row, $header_map, '重さ（g）', 5));
            $entry = [
                'type' => self::read_cell_by_header($row, $header_map, '種類', 0),
                'size' => self::read_cell_by_header($row, $header_map, 'サイズ', 1),
                'paper' => self::read_cell_by_header($row, $header_map, '紙質', 2),
                'thickness' => self::read_cell_by_header($row, $header_map, '厚み', 3),
                'tape' => self::read_cell_by_header($row, $header_map, 'テープ有無', 4),
                'weight_g' => $weight_g,
            ];

            if ($entry['type'] === '' && $entry['size'] === '' && $entry['paper'] === '' && $entry['thickness'] === '') {
                continue;
            }

            $entries[] = $entry;
        }

        if (empty($entries)) {
            return [];
        }

        return [
            'sheet_name' => $sheet_name,
            'parser' => 'paper_weight',
            'rows' => $entries,
        ];
    }

    /**
     * 郵便料金表を正規化。
     *
     * @param string $sheet_name シート名。
     * @param array  $rows      行配列。
     * @return array
     */
    private static function normalize_postage_table($sheet_name, $rows)
    {
        if (count($rows) < 2) {
            return [];
        }

        $header_map = self::build_header_index_map($rows[0]);
        $data_rows = array_slice($rows, 1);
        $entries = [];
        $by_size = [];

        foreach ($data_rows as $row) {
            $size = self::read_cell_by_header($row, $header_map, 'サイズ', 0);
            $max_g = self::to_float_number(self::read_cell_by_header($row, $header_map, 'g以内', 1));
            $fee = self::to_int_price(self::read_cell_by_header($row, $header_map, '料金', 2));

            if ($size === '' || $fee === null) {
                continue;
            }

            $entry = [
                'size' => $size,
                'max_g' => $max_g,
                'fee' => $fee,
            ];
            $entries[] = $entry;
            if (!isset($by_size[$size])) {
                $by_size[$size] = [];
            }
            $by_size[$size][] = $entry;
        }

        foreach ($by_size as $size => $list) {
            usort($list, function ($a, $b) {
                return ($a['max_g'] <=> $b['max_g']);
            });
            $by_size[$size] = $list;
        }

        if (empty($entries)) {
            return [];
        }

        return [
            'sheet_name' => $sheet_name,
            'parser' => 'postage',
            'rows' => $entries,
            'by_size' => $by_size,
        ];
    }

    /**
     * ヘッダー名 => インデックス変換。
     *
     * @param array $header_row ヘッダー行。
     * @return array
     */
    private static function build_header_index_map($header_row)
    {
        $map = [];
        foreach ((array) $header_row as $index => $label) {
            $trimmed = trim((string) $label);
            if ($trimmed !== '') {
                $map[$trimmed] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * ヘッダー名優先でセル値を取得。
     *
     * @param array  $row        データ行。
     * @param array  $header_map ヘッダーマップ。
     * @param string $header     ヘッダー名。
     * @param int    $fallback   フォールバック列。
     * @return string
     */
    private static function read_cell_by_header($row, $header_map, $header, $fallback)
    {
        $index = isset($header_map[$header]) ? (int) $header_map[$header] : (int) $fallback;
        if (!isset($row[$index])) {
            return '';
        }

        return trim((string) $row[$index]);
    }

    /**
     * シート名を "種類_サイズ_紙質_厚み_その他" で解釈。
     *
     * @param string $sheet_name シート名。
     * @return array
     */
    private static function parse_conditions_from_sheet_name($sheet_name)
    {
        $parts = array_map('trim', explode('_', (string) $sheet_name));

        return [
            'type' => isset($parts[0]) ? $parts[0] : '',
            'size' => isset($parts[1]) ? $parts[1] : '',
            'paper' => isset($parts[2]) ? $parts[2] : '',
            'thickness' => isset($parts[3]) ? $parts[3] : '',
            'other' => isset($parts[4]) ? $parts[4] : '',
            'raw' => $parts,
        ];
    }

    /**
     * 価格文字列を整数へ変換。
     *
     * @param mixed $value 値。
     * @return int|null
     */
    private static function to_int_price($value)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $num = preg_replace('/[^0-9.-]/', '', (string) $value);
        if ($num === '' || !is_numeric($num)) {
            return null;
        }

        return (int) round((float) $num);
    }

    /**
     * 数値文字列をfloatへ変換。
     *
     * @param mixed $value 値。
     * @return float|null
     */
    private static function to_float_number($value)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $num = preg_replace('/[^0-9.-]/', '', (string) $value);
        if ($num === '' || !is_numeric($num)) {
            return null;
        }

        return (float) $num;
    }
}
