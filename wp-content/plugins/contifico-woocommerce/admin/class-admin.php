<?php
/**
 * Funcionalidades del área de administración del plugin.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase responsable de la interacción del plugin en el escritorio.
 */
class Contifico_WooCommerce_Admin {

    /**
     * Versión del plugin.
     *
     * @var string
     */
    protected $version;

    /**
     * Instancia del gestor de ajustes.
     *
     * @var Contifico_WooCommerce_Admin_Settings
     */
    protected $settings;

    /**
     * Constructor.
     *
     * @param string                                $version  Versión actual del plugin.
     * @param Contifico_WooCommerce_Admin_Settings  $settings Gestor de ajustes.
     */
    public function __construct( $version, Contifico_WooCommerce_Admin_Settings $settings ) {
        $this->version  = $version;
        $this->settings = $settings;
    }

    /**
     * Registra los hooks que se utilizarán en el panel de administración.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'admin_notices', array( $this, 'maybe_render_credentials_notice' ) );
        add_filter( 'woocommerce_order_actions', array( $this, 'register_order_action' ) );
        add_action( 'woocommerce_order_action_contifico_sync', array( $this, 'process_manual_sync' ), 10, 1 );
    }

    /**
     * Agrega una acción personalizada en la pantalla de pedidos de WooCommerce.
     *
     * @param array $actions Lista de acciones disponibles.
     *
     * @return array
     */
    public function register_order_action( $actions ) {
        $actions['contifico_sync'] = __( 'Sincronizar con Contifico', 'contifico-woocommerce' );

        return $actions;
    }

    /**
     * Ejecuta la lógica de sincronización cuando se selecciona la acción manual.
     *
     * @param mixed $order Pedido que se va a sincronizar.
     *
     * @return void
     */
    public function process_manual_sync( $order ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        do_action( 'contifico_woocommerce_manual_order_sync', $order );
    }

    /**
     * Muestra una advertencia cuando faltan credenciales para la API.
     *
     * @return void
     */
    public function maybe_render_credentials_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( $this->settings->has_credentials() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen || false === strpos( $screen->id, 'woocommerce' ) ) {
            return;
        }

        $settings_url = admin_url( 'admin.php?page=contifico-woocommerce' );

        echo '<div class="notice notice-warning"><p>';
        printf(
            /* translators: %s URL hacia la página de ajustes del plugin. */
            esc_html__( 'Configura las credenciales de Contifico para habilitar la sincronización. %s.', 'contifico-woocommerce' ),
            sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url( $settings_url ),
                esc_html__( 'Ir a los ajustes', 'contifico-woocommerce' )
            )
        );
        echo '</p></div>';
    }
}
