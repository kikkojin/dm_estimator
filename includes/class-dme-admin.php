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
    }

    /**
     * サニタイズ。
     *
     * @param array $input 入力配列。
     * @return array
     */
    public static function sanitize_settings($input)
    {
        return [
            'recipient_email' => isset($input['recipient_email']) ? sanitize_email($input['recipient_email']) : '',
        ];
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
     * 設定画面。
     *
     * @return void
     */
    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>DM Estimator 設定</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('dme_settings_group');
                do_settings_sections('dme-settings');
                submit_button();
                ?>
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
}
