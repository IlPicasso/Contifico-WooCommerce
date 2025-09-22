<?php
/**
 * Funcionalidades públicas del plugin.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para registrar los hooks disponibles en el frontal de la tienda.
 */
class Contifico_WooCommerce_Public {

    /**
     * Versión del plugin.
     *
     * @var string
     */
    protected $version;

    /**
     * Constructor.
     *
     * @param string $version Versión actual del plugin.
     */
    public function __construct( $version ) {
        $this->version = $version;
    }

    /**
     * Registra los hooks públicos del plugin.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'woocommerce_checkout_create_order', array( $this, 'mark_order_for_sync' ), 20, 2 );
        add_action( 'woocommerce_thankyou', array( $this, 'maybe_queue_order' ), 10, 1 );
    }

    /**
     * Marca el pedido para sincronizarse con Contifico una vez confirmado.
     *
     * @param WC_Order $order Pedido que se está creando.
     * @param array    $data  Datos del checkout.
     *
     * @return void
     */
    public function mark_order_for_sync( $order, $data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $order->update_meta_data( '_contifico_sync_required', 'yes' );
    }

    /**
     * Dispara un evento personalizado para manejar la sincronización del pedido.
     *
     * @param int $order_id Identificador del pedido completado.
     *
     * @return void
     */
    public function maybe_queue_order( $order_id ) {
        $order_id = absint( $order_id );

        if ( ! $order_id ) {
            return;
        }

        $should_sync = get_post_meta( $order_id, '_contifico_sync_required', true );

        if ( 'yes' !== $should_sync ) {
            return;
        }

        do_action( 'contifico_woocommerce_queue_order', $order_id );
    }
}
