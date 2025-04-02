<?php
/**
 * Plugin Name: MotoPress Hotel Booking - Toss Payments Card Gateway
 * Plugin URI:  https://shoplic.kr
 * Description: Integrates Toss Payments (Credit Card) with MotoPress Hotel Booking.
 * Version:     1.0.0
 * Author:      shoplic
 * Author URI:  https://shoplic.kr
 * Text Domain: mphb-tosspayments-card
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * MPHB requires at least: 4.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define constants
define('MPHB_TOSSPAYMENTS_CARD_VERSION', '1.0.0');
define('MPHB_TOSSPAYMENTS_CARD_PLUGIN_FILE', __FILE__);
define('MPHB_TOSSPAYMENTS_CARD_PLUGIN_PATH', plugin_dir_path(MPHB_TOSSPAYMENTS_CARD_PLUGIN_FILE));
define('MPHB_TOSSPAYMENTS_CARD_PLUGIN_URL', plugin_dir_url(MPHB_TOSSPAYMENTS_CARD_PLUGIN_FILE));

// Check if MPHB is active
if (!class_exists('\MPHB\Plugin')) {
    add_action('admin_notices', function () {
        ?>
        <div class="error">
            <p><?php esc_html_e('MotoPress Hotel Booking - Toss Payments Card Gateway requires MotoPress Hotel Booking plugin to be active.', 'mphb-tosspayments-card'); ?></p>
        </div>
        <?php
    });
    return;
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MPHB\\TossPaymentsCard\\';
    $base_dir = __DIR__ . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});


/**
 * Initialize the gateway.
 */
function mphb_init_tosspayments_card_gateway() {
    // Ensure MPHB Gateway structure is loaded
    if (!class_exists('\MPHB\Payments\Gateways\Gateway')) {
        return;
    }

    // Instantiate the gateway
    new \MPHB\TossPaymentsCard\TossPaymentsCardGateway();
}
// Use mphb_init_gateways action to register the gateway
// This ensures it runs after MPHB's GatewayManager is ready
add_action('mphb_init_gateways', 'mphb_init_tosspayments_card_gateway', 20); // Priority 20 to run after built-in ones

/**
 * Load text domain for translations.
 */
function mphb_tosspayments_card_load_textdomain() {
    load_plugin_textdomain('mphb-tosspayments-card', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'mphb_tosspayments_card_load_textdomain');

