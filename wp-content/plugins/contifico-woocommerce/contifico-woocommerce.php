<?php
/**
 * Plugin Name:       Contifico WooCommerce
 * Plugin URI:        https://contifico.com
 * Description:       Integración de WooCommerce con Contifico.
 * Version:           1.0.0
 * Author:            Contifico
 * Author URI:        https://contifico.com
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       contifico-woocommerce
 * Domain Path:       /languages
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONTIFICO_WOOCOMMERCE_VERSION', '1.0.0' );
define( 'CONTIFICO_WOOCOMMERCE_PLUGIN_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-autoloader.php';

Contifico_WooCommerce_Autoloader::register();

register_activation_hook( __FILE__, array( 'Contifico_WooCommerce_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Contifico_WooCommerce_Plugin', 'deactivate' ) );

/**
 * Inicializa la ejecución del plugin.
 *
 * @return void
 */
function contifico_woocommerce_run() {
    $plugin = Contifico_WooCommerce_Plugin::instance();
    $plugin->run();
}

contifico_woocommerce_run();
