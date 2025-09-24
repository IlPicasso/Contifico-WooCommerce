<?php
/**
 * Bootstrap para ejecutar las pruebas unitarias.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/stubs/class-wp-error.php';

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return $text;
    }
}

if ( ! defined( 'CONTIFICO_WOOCOMMERCE_DISABLE_RETRY_DELAY' ) ) {
    define( 'CONTIFICO_WOOCOMMERCE_DISABLE_RETRY_DELAY', true );
}

require_once __DIR__ . '/../wp-content/plugins/contifico-woocommerce/includes/api/class-contifico-client.php';
require_once __DIR__ . '/../wp-content/plugins/contifico-woocommerce/includes/sync/class-inventory-sync.php';
