<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 管理画面の設定機能。
 */
class DME_Admin
{
    const OPTION_KEY = 'dme_settings';

    /**
     * 初期化。
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_dme_delete_price_cache', [__CLASS__, 'handle_delete_price_cache']);
    }

    /**
     * メニュー登録。
     *
     * @return void
     */
    public static function register_menu()
    {
        add_options_page(
            'DM Estimator',
            'DM Estimator',
            'manage_options',
            'dme-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    /**
     * 設定登録。
     *
     * @return void
     */
    public static function register_settings()
    {
        register_setting('dme_settings_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => [
                'recipient_email' => get_option('admin_email'),
                'google_api_key' => '',
                'disable_price_cache' => 0,
                'enable_verbose_log' => 0,
            ],
        ]);

        add_settings_section('dme_main_section', 'メール設定', '__return_false', 'dme-settings');

        add_settings_field(
            'recipient_email',
            '見積送信先メールアドレス',
            [__CLASS__, 'render_recipient_email_field'],
            'dme-settings',
            'dme_main_section'
        );

        add_settings_field(
            'google_api_key',
            'Google Sheets API Key',
            [__CLASS__, 'render_google_api_key_field'],
            'dme-settings',
            'dme_main_section'
        );

        add_settings_field(
            'disable_price_cache',
            '価格表キャッシュ',
            [__CLASS__, 'render_disable_price_cache_field'],
            'dme-settings',
            'dme_main_section'
        );

        add_settings_field(
            'enable_verbose_log',
            'ログ設定',
            [__CLASS__, 'render_enable_verbose_log_field'],
            'dme-settings',
            'dme_main_section'
        );
    }

    /**
     * サニタイズ。
     *
     * @param array $input 入力配列。
     * @return array
     */
    public static function sanitize_settings($input)
    {
        $current = get_option(self::OPTION_KEY, []);
        $next = [
            'recipient_email' => isset($input['recipient_email']) ? sanitize_email($input['recipient_email']) : '',
            'google_api_key' => isset($input['google_api_key']) ? sanitize_text_field($input['google_api_key']) : '',
            'disable_price_cache' => empty($input['disable_price_cache']) ? 0 : 1,
            'enable_verbose_log' => empty($input['enable_verbose_log']) ? 0 : 1,
        ];

        $api_key_changed = (string) ($current['google_api_key'] ?? '') !== $next['google_api_key'];
        $disable_cache_changed = (int) ($current['disable_price_cache'] ?? 0) !== (int) $next['disable_price_cache'];
        if ($api_key_changed || $disable_cache_changed) {
            DME_Sheets::clear_books_cache('Settings updated: API key or disable_price_cache changed');
        }

        return $next;
    }

    /**
     * 入力欄描画。
     *
     * @return void
     */
    public static function render_recipient_email_field()
    {
        $settings = get_option(self::OPTION_KEY, []);
        $value = isset($settings['recipient_email']) ? $settings['recipient_email'] : get_option('admin_email');
        ?>
        <input type="email" name="<?php echo esc_attr(self::OPTION_KEY); ?>[recipient_email]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">見積フォーム送信時の宛先になります。</p>
        <?php
    }

    /**
     * Google API キー入力欄描画。
     *
     * @return void
     */
    public static function render_google_api_key_field()
    {
        $settings = get_option(self::OPTION_KEY, []);
        $value = isset($settings['google_api_key']) ? (string) $settings['google_api_key'] : '';
        ?>
        <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[google_api_key]" value="<?php echo esc_attr($value); ?>" class="regular-text code" />
        <p class="description">Google Sheets API v4 の API Key を入力してください。未設定の場合、シート一覧を取得できません。</p>
        <?php
    }

    /**
     * キャッシュ無効化チェックボックス描画。
     *
     * @return void
     */
    public static function render_disable_price_cache_field()
    {
        $settings = get_option(self::OPTION_KEY, []);
        $checked = !empty($settings['disable_price_cache']);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[disable_price_cache]" value="1" <?php checked($checked); ?> />
            価格表キャッシュを無効化する（開発・確認用）
        </label>
        <p class="description">チェック中は、価格表データをキャッシュせず、ページ表示ごとにGoogleスプレッドシートから再取得します。通常運用ではOFFにしてください。</p>
        <?php
    }

    /**
     * 詳細ログ設定チェックボックス描画。
     *
     * @return void
     */
    public static function render_enable_verbose_log_field()
    {
        $settings = get_option(self::OPTION_KEY, []);
        $checked = !empty($settings['enable_verbose_log']);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_verbose_log]" value="1" <?php checked($checked); ?> />
            詳細ログを出力する（開発・調査用）
        </label>
        <p class="description">チェック中は、Google Sheets API のレスポンス先頭、CSV取得URL、CSV本文先頭、各シートのパース結果などを debug.log に出力します。ログが大きくなるため通常運用ではOFFにしてください。</p>
        <?php
    }

    /**
     * 設定画面。
     *
     * @return void
     */
    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $api_key = self::get_google_api_key();
        ?>
        <div class="wrap">
            <h1>DM Estimator 設定</h1>
            <?php if (isset($_GET['dme_cache_deleted']) && $_GET['dme_cache_deleted'] === '1') : ?>
                <div class="notice notice-success is-dismissible"><p>価格表キャッシュを削除しました。</p></div>
            <?php endif; ?>
            <?php if ($api_key === '') : ?>
                <div class="notice notice-warning"><p><strong>Google Sheets API Key が未設定です。</strong> 価格表のシート名を自動取得できないため、見積データが読み込まれません。</p></div>
                <?php self::debug_log_missing_api_key_notice(); ?>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('dme_settings_group');
                do_settings_sections('dme-settings');
                submit_button();
                ?>
            </form>
            <hr />
            <h2>キャッシュ操作</h2>
            <p>価格表キャッシュを手動で削除できます。</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dme_delete_price_cache_action', 'dme_delete_price_cache_nonce'); ?>
                <input type="hidden" name="action" value="dme_delete_price_cache" />
                <?php submit_button('価格表キャッシュを削除', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * 宛先メール取得。
     *
     * @return string
     */
    public static function get_recipient_email()
    {
        $settings = get_option(self::OPTION_KEY, []);
        if (!empty($settings['recipient_email']) && is_email($settings['recipient_email'])) {
            return $settings['recipient_email'];
        }
        return (string) get_option('admin_email');
    }

    /**
     * Google APIキー取得。
     *
     * @return string
     */
    public static function get_google_api_key()
    {
        $settings = get_option(self::OPTION_KEY, []);
        if (!empty($settings['google_api_key']) && is_string($settings['google_api_key'])) {
            return trim((string) $settings['google_api_key']);
        }
        return '';
    }

    /**
     * 価格表キャッシュ無効化設定。
     *
     * @return bool
     */
    public static function is_price_cache_disabled()
    {
        $settings = get_option(self::OPTION_KEY, []);
        return !empty($settings['disable_price_cache']);
    }

    /**
     * 詳細ログ出力設定。
     *
     * @return bool
     */
    public static function is_verbose_log_enabled()
    {
        $settings = get_option(self::OPTION_KEY, []);
        return !empty($settings['enable_verbose_log']);
    }

    /**
     * キャッシュ削除ボタン処理。
     *
     * @return void
     */
    public static function handle_delete_price_cache()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('dme_delete_price_cache_action', 'dme_delete_price_cache_nonce');

        DME_Sheets::clear_books_cache('Manual clear from admin settings');

        $redirect_url = add_query_arg(
            [
                'page' => 'dme-settings',
                'dme_cache_deleted' => '1',
            ],
            admin_url('options-general.php')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * APIキー未設定の警告をdebug.logへ出力。
     *
     * @return void
     */
    private static function debug_log_missing_api_key_notice()
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }
        error_log('[DM Estimator] Google Sheets API Key is missing. Please set it in Settings > DM Estimator.');
    }
}
