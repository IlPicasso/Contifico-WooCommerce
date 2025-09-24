<?php
/**
 * Gestiona los movimientos de stock entre bodegas al cambiar el estado de los pedidos.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Contifico_WooCommerce_Inventory_Transfer_Manager {

    /**
     * Meta key donde se almacena el estado de los movimientos realizados para un pedido.
     */
    const META_KEY = '_contifico_inventory_transfer_state';

    /**
     * Gestor de ajustes del plugin.
     *
     * @var Contifico_WooCommerce_Admin_Settings
     */
    protected $settings_manager;

    /**
     * Cliente HTTP para comunicarse con Contifico.
     *
     * @var Contifico_WooCommerce_Api_Contifico_Client
     */
    protected $client;

    /**
     * Logger opcional utilizado para depurar eventos.
     *
     * @var WC_Logger|null
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Contifico_WooCommerce_Admin_Settings       $settings Gestor de ajustes.
     * @param Contifico_WooCommerce_Api_Contifico_Client $client   Cliente HTTP hacia Contifico.
     * @param WC_Logger|null                             $logger   Logger opcional.
     */
    public function __construct( Contifico_WooCommerce_Admin_Settings $settings, Contifico_WooCommerce_Api_Contifico_Client $client, $logger = null ) {
        $this->settings_manager = $settings;
        $this->client           = $client;
        $this->logger           = $logger;
    }

    /**
     * Registra los hooks necesarios para escuchar cambios de estado.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_handle_status_change' ), 10, 4 );
    }

    /**
     * Comprueba si el cambio de estado amerita ejecutar un movimiento de inventario.
     *
     * @param int       $order_id   Identificador del pedido.
     * @param string    $old_status Estado anterior.
     * @param string    $new_status Nuevo estado.
     * @param WC_Order  $order      Instancia del pedido.
     *
     * @return void
     */
    public function maybe_handle_status_change( $order_id, $old_status, $new_status, $order = null ) {
        if ( ! $order instanceof WC_Order ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        }

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings = $this->get_transfer_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        $normalized_status = $this->normalize_status_key( $new_status );

        if ( in_array( $normalized_status, $settings['decrease_statuses'], true ) ) {
            $this->reserve_stock_for_order( $order, $settings );
            return;
        }

        if ( in_array( $normalized_status, $settings['restore_statuses'], true ) ) {
            $this->restore_stock_for_order( $order, $settings );
        }
    }

    /**
     * Ejecuta la transferencia desde la bodega física hacia la bodega web.
     *
     * @param WC_Order $order    Pedido en cuestión.
     * @param array    $settings Ajustes normalizados de la integración.
     *
     * @return void
     */
    protected function reserve_stock_for_order( WC_Order $order, array $settings ) {
        $state = $this->get_transfer_state( $order );

        if ( ! empty( $state['reserved'] ) ) {
            $this->log( 'debug', 'El pedido ya tiene un movimiento de reserva registrado.', array( 'order_id' => $order->get_id() ) );
            return;
        }

        $details = $this->build_transfer_details_from_order( $order );

        if ( empty( $details ) ) {
            $this->log( 'info', 'No se encontraron productos elegibles para transferir inventario.', array( 'order_id' => $order->get_id() ) );
            return;
        }

        $payload = $this->build_transfer_payload( $order, $settings['source'], $settings['destination'], $details, 'reserve' );

        $response = $this->client->create_inventory_transfer( $payload );

        if ( is_wp_error( $response ) ) {
            $message = sprintf( __( 'No se pudo reservar inventario en Contifico: %s', 'contifico-woocommerce' ), $response->get_error_message() );
            $order->add_order_note( $message );
            $this->log( 'error', $response->get_error_message(), array( 'order_id' => $order->get_id() ) );
            return;
        }

        $state = array(
            'reserved'        => true,
            'items'           => $details,
            'source'          => $settings['source'],
            'destination'     => $settings['destination'],
            'last_movement'   => 'reserve',
            'last_reference'  => isset( $response['codigo'] ) ? (string) $response['codigo'] : '',
            'updated_at_gmt'  => current_time( 'mysql', true ),
        );

        $order->update_meta_data( self::META_KEY, $state );
        $order->save();

        $note = __( 'Inventario reservado en la bodega web de Contifico.', 'contifico-woocommerce' );

        if ( ! empty( $state['last_reference'] ) ) {
            $note = sprintf( __( 'Inventario reservado en Contifico. Referencia: %s.', 'contifico-woocommerce' ), $state['last_reference'] );
        }

        $order->add_order_note( $note );
    }

    /**
     * Revierte una transferencia previa devolviendo el stock a la bodega física.
     *
     * @param WC_Order $order    Pedido en cuestión.
     * @param array    $settings Ajustes normalizados de la integración.
     *
     * @return void
     */
    protected function restore_stock_for_order( WC_Order $order, array $settings ) {
        $state = $this->get_transfer_state( $order );

        if ( empty( $state['reserved'] ) || empty( $state['items'] ) ) {
            $this->log( 'debug', 'No existe una reserva previa para revertir.', array( 'order_id' => $order->get_id() ) );
            return;
        }

        $source      = isset( $state['source'] ) ? $state['source'] : $settings['source'];
        $destination = isset( $state['destination'] ) ? $state['destination'] : $settings['destination'];

        $payload = $this->build_transfer_payload( $order, $destination, $source, $state['items'], 'restore' );

        $response = $this->client->create_inventory_transfer( $payload );

        if ( is_wp_error( $response ) ) {
            $message = sprintf( __( 'No se pudo revertir la transferencia de inventario: %s', 'contifico-woocommerce' ), $response->get_error_message() );
            $order->add_order_note( $message );
            $this->log( 'error', $response->get_error_message(), array( 'order_id' => $order->get_id() ) );
            return;
        }

        $state['reserved']       = false;
        $state['items']          = array();
        $state['last_movement']  = 'restore';
        $state['last_reference'] = isset( $response['codigo'] ) ? (string) $response['codigo'] : '';
        $state['updated_at_gmt'] = current_time( 'mysql', true );

        $order->update_meta_data( self::META_KEY, $state );
        $order->save();

        $note = __( 'Inventario devuelto a la bodega principal en Contifico.', 'contifico-woocommerce' );

        if ( ! empty( $state['last_reference'] ) ) {
            $note = sprintf( __( 'Inventario restituido en Contifico. Referencia: %s.', 'contifico-woocommerce' ), $state['last_reference'] );
        }

        $order->add_order_note( $note );
    }

    /**
     * Construye los detalles de productos a transferir en base a los artículos del pedido.
     *
     * @param WC_Order $order Pedido analizado.
     *
     * @return array
     */
    protected function build_transfer_details_from_order( WC_Order $order ) {
        $items = $order->get_items();

        if ( empty( $items ) ) {
            return array();
        }

        $grouped = array();

        foreach ( $items as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
                continue;
            }

            $product = $item->get_product();

            if ( ! $product ) {
                continue;
            }

            $contifico_id = $this->get_contifico_product_id( $product );

            if ( '' === $contifico_id ) {
                $this->log( 'notice', 'Se omitió un producto sin identificador de Contifico durante la transferencia.', array( 'order_id' => $order->get_id() ) );
                continue;
            }

            $quantity = $this->resolve_item_quantity( $item );

            if ( $quantity <= 0 ) {
                continue;
            }

            if ( isset( $grouped[ $contifico_id ] ) ) {
                $grouped[ $contifico_id ]['cantidad'] += $quantity;
            } else {
                $grouped[ $contifico_id ] = array(
                    'producto_id' => (string) $contifico_id,
                    'cantidad'    => $quantity,
                );
            }
        }

        return array_values( $grouped );
    }

    /**
     * Determina el identificador remoto del producto en Contifico.
     *
     * @param WC_Product $product Producto relacionado al pedido.
     *
     * @return string
     */
    protected function get_contifico_product_id( $product ) {
        if ( $product && method_exists( $product, 'get_meta' ) ) {
            $value = $product->get_meta( '_contifico_product_id', true );

            if ( '' !== $value ) {
                return (string) $value;
            }
        }

        $ids = array();

        if ( $product && method_exists( $product, 'get_id' ) ) {
            $ids[] = $product->get_id();
        }

        if ( $product && method_exists( $product, 'get_parent_id' ) ) {
            $parent = $product->get_parent_id();

            if ( $parent ) {
                $ids[] = $parent;
            }
        }

        foreach ( $ids as $candidate ) {
            $value = $this->get_post_meta_value( $candidate );

            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Recupera el meta `_contifico_product_id` de la base de datos si la función está disponible.
     *
     * @param int $product_id Identificador del producto.
     *
     * @return string
     */
    protected function get_post_meta_value( $product_id ) {
        if ( $product_id && function_exists( 'get_post_meta' ) ) {
            $value = get_post_meta( $product_id, '_contifico_product_id', true );

            if ( ! empty( $value ) ) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Calcula la cantidad asociada al artículo del pedido.
     *
     * @param WC_Order_Item_Product $item Elemento del pedido.
     *
     * @return float
     */
    protected function resolve_item_quantity( $item ) {
        $quantity = null;

        if ( method_exists( $item, 'get_meta' ) ) {
            $reduced = $item->get_meta( '_reduced_stock', true );

            if ( '' !== $reduced ) {
                $quantity = $reduced;
            }
        }

        if ( null === $quantity && method_exists( $item, 'get_quantity' ) ) {
            $quantity = $item->get_quantity();
        }

        if ( null === $quantity ) {
            return 0;
        }

        if ( function_exists( 'wc_stock_amount' ) ) {
            $quantity = wc_stock_amount( $quantity );
        } else {
            $quantity = (float) $quantity;
        }

        return $quantity > 0 ? (float) $quantity : 0;
    }

    /**
     * Construye el payload que se enviará a Contifico.
     *
     * @param WC_Order $order            Pedido involucrado.
     * @param string   $source           Bodega origen.
     * @param string   $destination      Bodega destino.
     * @param array    $details          Detalles de productos.
     * @param string   $movement_context Contexto del movimiento (reserve|restore).
     *
     * @return array
     */
    protected function build_transfer_payload( WC_Order $order, $source, $destination, array $details, $movement_context ) {
        $description = 'reserve' === $movement_context
            ? __( 'Transferencia automática desde WooCommerce para reservar inventario.', 'contifico-woocommerce' )
            : __( 'Transferencia automática desde WooCommerce para liberar inventario.', 'contifico-woocommerce' );

        if ( method_exists( $order, 'get_order_number' ) ) {
            $description = sprintf( '%s %s', $description, '#' . $order->get_order_number() );
        }

        return array(
            'tipo'              => 'TRA',
            'fecha'             => function_exists( 'gmdate' ) ? gmdate( 'd/m/Y' ) : date( 'd/m/Y' ),
            'bodega_id'         => (string) $source,
            'bodega_destino_id' => (string) $destination,
            'detalles'          => $details,
            'descripcion'       => $description,
        );
    }

    /**
     * Obtiene los ajustes necesarios para determinar si se debe actuar.
     *
     * @return array
     */
    protected function get_transfer_settings() {
        $settings = $this->settings_manager->get_settings();

        $enabled = isset( $settings['inventory_transfer_enabled'] ) && 'yes' === $settings['inventory_transfer_enabled'];
        $source  = isset( $settings['inventory_transfer_source'] ) ? $settings['inventory_transfer_source'] : '';
        $dest    = isset( $settings['inventory_transfer_destination'] ) ? $settings['inventory_transfer_destination'] : '';

        $decrease = isset( $settings['inventory_transfer_decrease_statuses'] ) && is_array( $settings['inventory_transfer_decrease_statuses'] )
            ? array_map( array( $this, 'normalize_status_key' ), $settings['inventory_transfer_decrease_statuses'] )
            : array();

        $restore = isset( $settings['inventory_transfer_restore_statuses'] ) && is_array( $settings['inventory_transfer_restore_statuses'] )
            ? array_map( array( $this, 'normalize_status_key' ), $settings['inventory_transfer_restore_statuses'] )
            : array();

        if ( '' === $source || '' === $dest || empty( $decrease ) || empty( $restore ) ) {
            $enabled = false;
        }

        return array(
            'enabled'           => $enabled,
            'source'            => (string) $source,
            'destination'       => (string) $dest,
            'decrease_statuses' => $decrease,
            'restore_statuses'  => $restore,
        );
    }

    /**
     * Recupera el estado almacenado de transferencias para el pedido.
     *
     * @param WC_Order $order Pedido consultado.
     *
     * @return array
     */
    protected function get_transfer_state( WC_Order $order ) {
        $state = $order->get_meta( self::META_KEY );

        return is_array( $state ) ? $state : array();
    }

    /**
     * Normaliza el identificador de estado de pedido para facilitar comparaciones.
     *
     * @param string $status Estado a normalizar.
     *
     * @return string
     */
    protected function normalize_status_key( $status ) {
        $status = strtolower( (string) $status );

        if ( 0 === strpos( $status, 'wc-' ) ) {
            $status = substr( $status, 3 );
        }

        return $status;
    }

    /**
     * Envía mensajes al logger cuando está disponible.
     *
     * @param string $level   Nivel del mensaje.
     * @param string $message Contenido a registrar.
     * @param array  $context Datos adicionales.
     *
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
        if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
            return;
        }

        $this->logger->log( $level, $message, array_merge( array( 'source' => Contifico_WooCommerce_Api_Contifico_Client::LOG_SOURCE ), $context ) );
    }
}
