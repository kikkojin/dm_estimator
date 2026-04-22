<?php
/**
 * Plugin Name: DM Estimator
 * Description: DM見積もりフォーム用の試作プラグイン
 * Version: 0.1.0
 * Author: Chiaki Kikkojin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 管理画面メニュー追加
 */
function dm_estimator_add_admin_menu()
{
    add_menu_page(
        esc_html__('DM見積もり設定', 'dm-estimator'),
        esc_html__('DM見積もり設定', 'dm-estimator'),
        'manage_options',
        'dm-estimator-settings',
        'dm_estimator_render_settings_page',
        'dashicons-feedback',
        56
    );
}
add_action('admin_menu', 'dm_estimator_add_admin_menu');

/**
 * 管理画面ページ描画
 */
function dm_estimator_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('DM見積もり設定', 'dm-estimator') . '</h1>';
    echo '<p>' . esc_html__('ここではDM見積もりフォームに関する設定を行う予定です。', 'dm-estimator') . '</p>';
    echo '</div>';
}

/**
 * ショートコード登録
 */
function dm_estimator_register_shortcode()
{
    add_shortcode('dm_estimator_form', 'dm_estimator_render_form_shortcode');
}
add_action('init', 'dm_estimator_register_shortcode');

/**
 * ショートコード表示処理
 *
 * @return string
 */
function dm_estimator_render_form_shortcode()
{
    $result_message = '';

    $name = '';
    $email = '';
    $quantity = '';
    $note = '';

    if (
        isset($_POST['dm_estimator_submit'], $_POST['dm_estimator_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dm_estimator_nonce'])), 'dm_estimator_submit_action')
    ) {
        $name = isset($_POST['dm_name']) ? sanitize_text_field(wp_unslash($_POST['dm_name'])) : '';
        $email = isset($_POST['dm_email']) ? sanitize_email(wp_unslash($_POST['dm_email'])) : '';
        $quantity = isset($_POST['dm_quantity']) ? absint(wp_unslash($_POST['dm_quantity'])) : 0;
        $note = isset($_POST['dm_note']) ? sanitize_textarea_field(wp_unslash($_POST['dm_note'])) : '';

        $result_message = sprintf(
            /* translators: 1: customer name 2: email 3: quantity */
            esc_html__('仮送信を受け付けました: お名前=%1$s / メール=%2$s / 部数=%3$s', 'dm-estimator'),
            $name !== '' ? $name : esc_html__('未入力', 'dm-estimator'),
            $email !== '' ? $email : esc_html__('未入力', 'dm-estimator'),
            $quantity > 0 ? (string) $quantity : esc_html__('未入力', 'dm-estimator')
        );
    }

    ob_start();
    ?>
    <div class="dm-estimator-form-wrap">
        <h2><?php echo esc_html__('DM見積もり（仮フォーム）', 'dm-estimator'); ?></h2>

        <?php if ($result_message !== '') : ?>
            <p class="dm-estimator-result"><?php echo esc_html($result_message); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(get_permalink()); ?>">
            <?php wp_nonce_field('dm_estimator_submit_action', 'dm_estimator_nonce'); ?>

            <p>
                <label for="dm_name"><?php echo esc_html__('お名前', 'dm-estimator'); ?></label><br>
                <input
                    type="text"
                    id="dm_name"
                    name="dm_name"
                    value="<?php echo esc_attr($name); ?>"
                    maxlength="100"
                >
            </p>

            <p>
                <label for="dm_email"><?php echo esc_html__('メールアドレス', 'dm-estimator'); ?></label><br>
                <input
                    type="email"
                    id="dm_email"
                    name="dm_email"
                    value="<?php echo esc_attr($email); ?>"
                    maxlength="190"
                >
            </p>

            <p>
                <label for="dm_quantity"><?php echo esc_html__('部数', 'dm-estimator'); ?></label><br>
                <input
                    type="number"
                    id="dm_quantity"
                    name="dm_quantity"
                    min="1"
                    step="1"
                    value="<?php echo esc_attr((string) $quantity); ?>"
                >
            </p>

            <p>
                <label for="dm_note"><?php echo esc_html__('備考', 'dm-estimator'); ?></label><br>
                <textarea id="dm_note" name="dm_note" rows="4" cols="40"><?php echo esc_textarea($note); ?></textarea>
            </p>

            <p>
                <button type="submit" name="dm_estimator_submit" value="1"><?php echo esc_html__('仮送信する', 'dm-estimator'); ?></button>
            </p>
        </form>
    </div>
    <?php

    return (string) ob_get_clean();
}
