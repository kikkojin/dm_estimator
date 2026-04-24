<?php
/**
 * Plugin Name: DM Estimator
 * Description: 印刷・DM発送見積もりを自動計算し、明細付きで問い合わせメール送信できるプラグイン。
 * Version: 1.0.0
 * Author: Chiaki Kikkojin
 * Text Domain: dm-estimator
 */

if (!defined('ABSPATH')) {
    exit;
}

// 定数定義（将来の拡張でパス参照を統一するため）。
define('DME_VERSION', '1.0.0');
define('DME_PLUGIN_FILE', __FILE__);
define('DME_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DME_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DME_PLUGIN_DIR . 'includes/class-dme-sheets.php';
require_once DME_PLUGIN_DIR . 'includes/class-dme-pricing.php';
require_once DME_PLUGIN_DIR . 'includes/class-dme-admin.php';
require_once DME_PLUGIN_DIR . 'includes/class-dme-ajax.php';
require_once DME_PLUGIN_DIR . 'includes/class-dme-plugin.php';

DME_Plugin::init();
