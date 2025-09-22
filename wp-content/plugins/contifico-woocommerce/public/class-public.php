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
        add_filter( 'woocommerce_get_availability', array( $this, 'append_inventory_to_availability' ), 20, 2 );
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_inventory_by_warehouse' ), 15 );
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

    /**
     * Añade información de stock local y en línea en el texto de disponibilidad.
     *
     * @param array      $availability Información de disponibilidad existente.
     * @param WC_Product $product      Producto que se está evaluando.
     *
     * @return array
     */
    public function append_inventory_to_availability( $availability, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $availability;
        }

        $inventory = $this->get_inventory_for_product( $product );

        if ( empty( $inventory ) ) {
            return $availability;
        }

        $local_id  = $this->get_local_warehouse_id();
        $totals    = $this->split_inventory_scope( $inventory, $local_id );
        $local     = $this->format_stock_quantity_message( $totals['local'] );
        $online    = $this->format_stock_quantity_message( $totals['online'] );
        $summary   = sprintf(
            /* translators: 1: local stock amount, 2: online stock amount */
            esc_html__( 'Disponible local: %1$s | En línea: %2$s', 'contifico-woocommerce' ),
            $local,
            $online
        );

        if ( empty( $availability['availability'] ) ) {
            $availability['availability'] = $summary;
        } else {
            $availability['availability'] .= ' ' . $summary;
        }

        return $availability;
    }

    /**
     * Muestra una lista con el stock por bodega dentro del listado de productos.
     *
     * @return void
     */
    public function render_inventory_by_warehouse() {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;

        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $inventory = $this->get_inventory_for_product( $product );

        if ( empty( $inventory ) ) {
            return;
        }

        $local_id = $this->get_local_warehouse_id();

        echo '<div class="contifico-warehouse-availability">';
        echo '<span class="contifico-warehouse-availability__title">' . esc_html__( 'Stock por bodega:', 'contifico-woocommerce' ) . '</span>';
        echo '<ul class="contifico-warehouse-availability__list">';

        foreach ( $inventory as $warehouse_id => $data ) {
            $name        = isset( $data['name'] ) && '' !== $data['name'] ? $data['name'] : $warehouse_id;
            $scope_label = ( '' !== $local_id && (string) $warehouse_id === (string) $local_id )
                ? esc_html__( 'Local', 'contifico-woocommerce' )
                : esc_html__( 'En línea', 'contifico-woocommerce' );
            $stock_label = $this->format_stock_quantity_message( isset( $data['stock'] ) ? $data['stock'] : 0 );

            printf(
                '<li class="contifico-warehouse-availability__item"><span class="contifico-warehouse-availability__name">%1$s</span> <span class="contifico-warehouse-availability__scope">(%2$s)</span>: <span class="contifico-warehouse-availability__stock">%3$s</span></li>',
                esc_html( $name ),
                esc_html( $scope_label ),
                esc_html( $stock_label )
            );
        }

        echo '</ul>';
        echo '</div>';
    }

    /**
     * Obtiene la información de inventario almacenada para un producto.
     *
     * @param WC_Product $product Producto a evaluar.
     *
     * @return array
     */
    protected function get_inventory_for_product( WC_Product $product ) {
        $meta_key = class_exists( 'Contifico_WooCommerce_Sync_Inventory_Sync' )
            ? Contifico_WooCommerce_Sync_Inventory_Sync::get_meta_key()
            : '_contifico_stock_by_warehouse';

        $inventory = get_post_meta( $product->get_id(), $meta_key, true );

        if ( ! is_array( $inventory ) ) {
            return array();
        }

        $normalized = array();

        foreach ( $inventory as $warehouse_id => $data ) {
            if ( ! is_array( $data ) ) {
                continue;
            }

            $warehouse_id = (string) $warehouse_id;
            $stock_value  = isset( $data['stock'] ) ? $data['stock'] : 0;

            if ( function_exists( 'wc_stock_amount' ) ) {
                $stock_value = wc_stock_amount( $stock_value );
            } else {
                $stock_value = floatval( $stock_value );
            }

            if ( $stock_value < 0 ) {
                $stock_value = 0;
            }

            $normalized[ $warehouse_id ] = array(
                'stock' => (float) $stock_value,
                'name'  => isset( $data['name'] ) ? (string) $data['name'] : $warehouse_id,
            );
        }

        return $normalized;
    }

    /**
     * Obtiene el identificador de la bodega local definida en los ajustes.
     *
     * @return string
     */
    protected function get_local_warehouse_id() {
        $settings = get_option( 'contifico_woocommerce_settings', array() );

        return isset( $settings['warehouse'] ) ? (string) $settings['warehouse'] : '';
    }

    /**
     * Divide el inventario en stock local y en línea.
     *
     * @param array  $inventory     Información de inventario por bodega.
     * @param string $local_id      Identificador de la bodega local.
     *
     * @return array
     */
    protected function split_inventory_scope( array $inventory, $local_id ) {
        $totals = array(
            'local'  => 0,
            'online' => 0,
        );

        foreach ( $inventory as $warehouse_id => $data ) {
            $amount = isset( $data['stock'] ) ? (float) $data['stock'] : 0;

            if ( '' !== $local_id && (string) $warehouse_id === (string) $local_id ) {
                $totals['local'] += $amount;
            } else {
                $totals['online'] += $amount;
            }
        }

        return $totals;
    }

    /**
     * Devuelve una etiqueta legible para una cantidad de stock.
     *
     * @param float $amount Cantidad disponible.
     *
     * @return string
     */
    protected function format_stock_quantity_message( $amount ) {
        $amount   = max( 0, (float) $amount );
        $rounded  = (int) round( $amount );
        $quantity = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $amount, 0 ) : number_format_i18n( $amount, 0 );

        $template = _n( '%s unidad', '%s unidades', $rounded, 'contifico-woocommerce' );
        $label    = sprintf( $template, $quantity );

        return esc_html( $label );
    }
}
