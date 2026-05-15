<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * プラグイン全体の初期化クラス。
 */
class DME_Plugin
{
    /**
     * 初期化。
     *
     * @return void
     */
    public static function init()
    {
        add_action('init', [__CLASS__, 'register_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action(DME_Sheets::CRON_HOOK, [__CLASS__, 'run_price_cache_cron']);

        DME_Admin::init();
        DME_Ajax::init();
    }

    /**
     * 有効化時: Cron登録（重複防止）。
     *
     * @return void
     */
    public static function activate()
    {
        if (!wp_next_scheduled(DME_Sheets::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', DME_Sheets::CRON_HOOK);
        }
    }

    /**
     * 停止時: Cron登録解除。
     *
     * @return void
     */
    public static function deactivate()
    {
        $timestamp = wp_next_scheduled(DME_Sheets::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, DME_Sheets::CRON_HOOK);
        }
        wp_clear_scheduled_hook(DME_Sheets::CRON_HOOK);
    }

    /**
     * Cron本体。
     *
     * @return void
     */
    public static function run_price_cache_cron()
    {
        DME_Sheets::refresh_books_cache('cron');
    }

    /**
     * フロントアセット登録。
     *
     * @return void
     */
    public static function register_assets()
    {
        wp_register_style(
            'dme-app',
            DME_PLUGIN_URL . 'assets/css/dme-app.css',
            [],
            DME_VERSION
        );

        wp_register_script(
            'dme-app',
            DME_PLUGIN_URL . 'assets/js/dme-app.js',
            [],
            DME_VERSION,
            true
        );
    }

    /**
     * ショートコード登録。
     *
     * @return void
     */
    public static function register_shortcode()
    {
        add_shortcode('dm_estimator_form', [__CLASS__, 'render_shortcode']);
    }

    /**
     * ショートコード描画。
     *
     * @return string
     */
    public static function render_shortcode()
    {
        wp_enqueue_style('dme-app');
        wp_enqueue_script('dme-app');

        $catalog_payload = DME_Sheets::build_front_catalog();

        wp_localize_script('dme-app', 'DME_APP', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dme_nonce'),
            'version' => DME_VERSION,
            'catalog' => $catalog_payload,
            'labels' => [
                'cannotEstimate' => '見積不可',
                'currencyPrefix' => '¥',
            ],
        ]);

        ob_start();
        ?>
        <div class="dme-root" id="dme-root">
            <p class="dme-note">条件を選択すると見積がリアルタイム更新されます。見積不可時は送信できません。</p>

            <div class="dme-card">
                <h3>基本条件</h3>
                <div class="dme-grid dme-grid-3">
                    <fieldset class="dme-label-full">
                        <legend>発送方法を選択してください。（メール便のご利用は、信書に該当しないものに限られます。）</legend>
                        <div class="dme-radio-group">
                            <label><input data-field="shipMethod" type="radio" name="dme_ship_method" value="mail">メール便</label>
                            <label><input data-field="shipMethod" type="radio" name="dme_ship_method" value="post">郵送</label>
                        </div>
                    </fieldset>
                    <label>発送する部数は何部ですか。
                        <input data-field="shipCount" type="number" min="1" step="1" value="1000">
                    </label>
                </div>
            </div>

            <div class="dme-card">
                <h3>往信用封筒</h3>
                <div class="dme-grid dme-grid-3">
                    <fieldset class="dme-label-full">
                        <legend>往信用封筒を使用しますか</legend>
                        <div class="dme-radio-group">
                            <label><input data-field="envelope.use" type="radio" name="dme_envelope_use" value="no">使用しない（ハガキ・圧着DMなどを使用する）</label>
                            <label><input data-field="envelope.use" type="radio" name="dme_envelope_use" value="yes">往信用封筒を使用する</label>
                        </div>
                    </fieldset>
                    <fieldset class="dme-label-full">
                        <legend>発送内容を選択してください。</legend>
                        <div class="dme-radio-group">
                            <label><input data-field="workType" type="radio" name="dme_work_type" value="dm">DM発送</label>
                            <label><input data-field="workType" type="radio" name="dme_work_type" value="survey">アンケート発送</label>
                        </div>
                    </fieldset>
                    <fieldset data-envelope-block="mode">
                        <legend>往信用封筒の種類を選択してください。</legend>
                        <div class="dme-radio-group dme-radio-group-nowrap">
                            <label><input data-field="envelope.mode" type="radio" name="dme_envelope_mode" value="supplied">支給</label>
                            <label><input data-field="envelope.mode" type="radio" name="dme_envelope_mode" value="clear">透明封筒</label>
                            <label><input data-field="envelope.mode" type="radio" name="dme_envelope_mode" value="print">封筒印刷</label>
                        </div>
                    </fieldset>
                    <label class="dme-label-full" data-envelope-block="count">封筒の部数を入力してください。
                        <input data-field="envelope.count" type="number" min="1" step="1" value="100">
                    </label>
                </div>
                <div class="dme-grid dme-grid-4" data-envelope-block="detail">
                    <label data-envelope-field="size">サイズ<select data-field="envelope.size"></select></label>
                    <label data-envelope-field="paper">紙質<select data-field="envelope.paper"></select></label>
                    <label data-envelope-field="thickness">厚み<select data-field="envelope.thickness"></select></label>
                    <fieldset data-envelope-field="tape">
                        <legend>テープ有無</legend>
                        <div class="dme-radio-group dme-radio-group-nowrap">
                            <label><input data-field="envelope.tape" type="radio" name="dme_envelope_tape" value="" checked>なし</label>
                            <label><input data-field="envelope.tape" type="radio" name="dme_envelope_tape" value="あり">あり</label>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="dme-card dme-hidden" data-reply-block="container">
                <h3>返信方法</h3>
                <div class="dme-grid dme-grid-3">
                    <fieldset>
                        <legend>返信の方法を選択してください。</legend>
                        <div class="dme-radio-group">
                            <label><input data-field="replyMode" type="radio" name="dme_reply_mode" value="receiver">受取人払い</label>
                            <label><input data-field="replyMode" type="radio" name="dme_reply_mode" value="stamp" checked>切手</label>
                        </div>
                    </fieldset>
                    <fieldset class="dme-hidden" data-reply-block="delegate">
                        <legend>受取人払い申請の代行を希望しますか。</legend>
                        <div class="dme-radio-group">
                            <label><input data-field="reply.delegate" type="radio" name="dme_reply_delegate" value="1">依頼する</label>
                            <label><input data-field="reply.delegate" type="radio" name="dme_reply_delegate" value="0" checked>自社で行う</label>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="dme-card">
                <h3>内容物（複数追加可）</h3>
                <div id="dme-contents"></div>
                <button type="button" id="dme-add-content">内容物を追加</button>
            </div>

            <div class="dme-card">
                <h3>見積明細</h3>
                <table class="dme-table">
                    <thead><tr><th>項目</th><th>単価</th><th>数量</th><th>小計</th><th>備考</th><th></th></tr></thead>
                    <tbody id="dme-estimate-body"></tbody>
                    <tfoot><tr><th colspan="3">合計</th><th id="dme-total">¥0</th><th colspan="2" id="dme-status">入力待ち</th></tr></tfoot>
                </table>
            </div>

            <div class="dme-card">
                <h3>お問い合わせ情報</h3>
                <div class="dme-grid dme-grid-3">
                    <label>会社名<input data-contact="company" type="text"></label>
                    <label>氏名<input data-contact="name" type="text"></label>
                    <label>メール<input data-contact="email" type="email"></label>
                    <label>電話番号<input data-contact="phone" type="text"></label>
                    <label>住所<input data-contact="address" type="text"></label>
                    <label>希望日<input data-contact="desired_date" type="date"></label>
                </div>
                <label>備考<textarea data-contact="note" rows="4"></textarea></label>

                <button type="button" id="dme-send">見積内容を送信</button>
                <p id="dme-send-result"></p>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
