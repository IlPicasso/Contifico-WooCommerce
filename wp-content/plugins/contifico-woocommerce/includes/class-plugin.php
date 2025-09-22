<?php
/**
 * Clase principal del plugin Contifico WooCommerce.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase que coordina la carga de dependencias y hooks.
 */
class Contifico_WooCommerce_Plugin {

    /**
     * Instancia única del plugin.
     *
     * @var Contifico_WooCommerce_Plugin|null
     */
    protected static $instance = null;

    /**
     * Instancia para la parte administrativa del plugin.
     *
     * @var Contifico_WooCommerce_Admin
     */
    protected $admin;

    /**
     * Instancia para la parte pública del plugin.
     *
     * @var Contifico_WooCommerce_Public
     */
    protected $public;

    /**
     * Gestor de ajustes del plugin.
     *
     * @var Contifico_WooCommerce_Admin_Settings
     */
    protected $settings;

    /**
     * Versión actual del plugin.
     *
     * @var string
     */
    protected $version;

    /**
     * Constructor privado para controlar la inicialización.
     */
    private function __construct() {
        $this->version = defined( 'CONTIFICO_WOOCOMMERCE_VERSION' ) ? CONTIFICO_WOOCOMMERCE_VERSION : '1.0.0';
    }

    /**
     * Obtiene la instancia singleton del plugin.
     *
     * @return Contifico_WooCommerce_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Inicia los hooks base para cargar el plugin.
     *
     * @return void
     */
    public function run() {
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
    }

    /**
     * Carga el archivo de traducciones.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'contifico-woocommerce', false, dirname( plugin_basename( CONTIFICO_WOOCOMMERCE_PLUGIN_FILE ) ) . '/languages/' );
    }

    /**
     * Inicializa dependencias y hooks una vez que todos los plugins están cargados.
     *
     * @return void
     */
    public function bootstrap() {
        if ( ! $this->is_woocommerce_active() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        $this->settings = new Contifico_WooCommerce_Admin_Settings();
        $this->admin    = new Contifico_WooCommerce_Admin( $this->version, $this->settings );
        $this->public   = new Contifico_WooCommerce_Public( $this->version );

        $this->settings->init();
        $this->admin->init_hooks();
        $this->public->init_hooks();
    }

    /**
     * Muestra un aviso si WooCommerce no está disponible.
     *
     * @return void
     */
    public function woocommerce_missing_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        esc_html_e( 'Contifico WooCommerce requiere que el plugin WooCommerce esté activo.', 'contifico-woocommerce' );
        echo '</p></div>';
    }

    /**
     * Comprueba si WooCommerce está activo.
     *
     * @return bool
     */
    protected function is_woocommerce_active() {
        if ( class_exists( 'WooCommerce' ) ) {
            return true;
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active( 'woocommerce/woocommerce.php' );
    }

    /**
     * Lógica que se ejecuta al activar el plugin.
     *
     * @return void
     */
    public static function activate() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( CONTIFICO_WOOCOMMERCE_PLUGIN_FILE ) );
            wp_die( esc_html__( 'Contifico WooCommerce requiere WooCommerce para activarse.', 'contifico-woocommerce' ) );
        }

        if ( false === get_option( 'contifico_woocommerce_settings', false ) ) {
            add_option(
                'contifico_woocommerce_settings',
                array(
                    'api_url'       => '',
                    'api_key'       => '',
                    'api_secret'    => '',
                    'warehouse'     => '',
                    'sync_statuses' => array(),
                )
            );
        }

        update_option( 'contifico_woocommerce_version', defined( 'CONTIFICO_WOOCOMMERCE_VERSION' ) ? CONTIFICO_WOOCOMMERCE_VERSION : '1.0.0' );
    }

    /**
     * Lógica que se ejecuta al desactivar el plugin.
     *
     * @return void
     */
    public static function deactivate() {
        delete_option( 'contifico_woocommerce_version' );
    }
}
