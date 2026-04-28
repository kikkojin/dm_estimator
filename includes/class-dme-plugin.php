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

        DME_Admin::init();
        DME_Ajax::init();
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
            'catalog' => $catalog_payload,
            'labels' => [
                'cannotEstimate' => '見積不可',
                'currencyPrefix' => '¥',
            ],
        ]);

        ob_start();
        ?>
        <div class="dme-root" id="dme-root">
            <h2>DM見積もりフォーム</h2>
            <p class="dme-note">条件を選択すると見積がリアルタイム更新されます。見積不可時は送信できません。</p>

            <div class="dme-card">
                <h3>基本条件</h3>
                <div class="dme-grid dme-grid-3">
                    <label>作業内容を選択してください。
                        <select data-field="workType">
                            <option value="">選択してください</option>
                            <option value="dm">DM発送</option>
                            <option value="survey">アンケート発送</option>
                        </select>
                    </label>
                    <label>発送方法を選択してください。（メール便のご利用は、信書に該当しないものに限られます。）
                        <select data-field="shipMethod">
                            <option value="">選択してください</option>
                            <option value="mail">メール便</option>
                            <option value="post">郵送</option>
                        </select>
                    </label>
                    <label>発送する部数は何部ですか。
                        <input data-field="shipCount" type="number" min="1" step="1" value="100">
                    </label>
                </div>
            </div>

            <div class="dme-card">
                <h3>封筒</h3>
                <div class="dme-grid dme-grid-3">
                    <label>往信用の封筒は使用しますか。
                        <select data-field="envelope.use">
                            <option value="no">なし</option>
                            <option value="yes">あり</option>
                        </select>
                    </label>
                    <label>封筒の種類を選択してください。
                        <select data-field="envelope.mode">
                            <option value="supplied">支給</option>
                            <option value="clear">透明封筒</option>
                            <option value="print">封筒印刷</option>
                        </select>
                    </label>
                    <label>封筒の部数を入力してください。
                        <input data-field="envelope.count" type="number" min="1" step="1" value="100">
                    </label>
                </div>
                <div class="dme-grid dme-grid-4">
                    <label>サイズ<select data-field="envelope.size"></select></label>
                    <label>紙質<select data-field="envelope.paper"></select></label>
                    <label>厚み<select data-field="envelope.thickness"></select></label>
                    <label>テープ<select data-field="envelope.tape"><option value="">なし</option><option value="あり">あり</option></select></label>
                </div>
            </div>

            <div class="dme-card">
                <h3>返信方法</h3>
                <div class="dme-grid dme-grid-3">
                    <label>返信の方法を選択してください。
                        <select data-field="replyMode">
                            <option value="stamp">切手</option>
                            <option value="receiver">受取人払い</option>
                        </select>
                    </label>
                    <label>受取人払い申請の代行を希望しますか。
                        <select data-field="reply.delegate">
                            <option value="0">自社で行う</option>
                            <option value="1">依頼する</option>
                        </select>
                    </label>
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
