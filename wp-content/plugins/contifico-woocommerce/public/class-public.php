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
        add_filter( 'woocommerce_checkout_fields', array( $this, 'register_checkout_tax_fields' ) );
        add_filter( 'woocommerce_checkout_get_value', array( $this, 'populate_checkout_tax_value' ), 10, 2 );
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_tax_fields' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_order_tax_fields' ) );
        add_action( 'woocommerce_edit_account_form', array( $this, 'render_account_tax_fields' ) );
        add_action( 'woocommerce_save_account_details', array( $this, 'save_account_details_fields' ), 10, 1 );
        add_action( 'personal_options_update', array( $this, 'save_account_details_fields' ), 10, 1 );
        add_action( 'edit_user_profile_update', array( $this, 'save_account_details_fields' ), 10, 1 );
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

    /**
     * Registra los campos tributarios en el checkout.
     *
     * @param array $fields Conjunto de campos actuales.
     *
     * @return array
     */
    public function register_checkout_tax_fields( $fields ) {
        if ( ! class_exists( 'Contifico_WooCommerce_Tax_Helper' ) ) {
            return $fields;
        }

        $tax_fields = Contifico_WooCommerce_Tax_Helper::get_account_fields( true );

        if ( empty( $tax_fields ) ) {
            return $fields;
        }

        if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            $fields['billing'] = array();
        }

        foreach ( $tax_fields as $key => $args ) {
            if ( isset( $args['value'] ) ) {
                $args['default'] = $args['value'];
                unset( $args['value'] );
            }

            $fields['billing'][ $key ] = $args;
        }

        return $fields;
    }

    /**
     * Precarga los valores de identificación cuando el usuario ha iniciado sesión.
     *
     * @param mixed  $value Valor actual.
     * @param string $key   Clave del campo.
     *
     * @return mixed
     */
    public function populate_checkout_tax_value( $value, $key ) {
        $allowed = array( 'taxpayer_type', 'tax_subject', 'tax_type', 'tax_id' );

        if ( ! in_array( $key, $allowed, true ) ) {
            return $value;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

        if ( $user_id <= 0 ) {
            return $value;
        }

        $stored = get_user_meta( $user_id, $key, true );

        if ( '' === $stored ) {
            return $value;
        }

        if ( 'tax_subject' === $key ) {
            return Contifico_WooCommerce_Tax_Helper::sanitize_subject_value( $stored );
        }

        return $stored;
    }

    /**
     * Valida los campos tributarios durante el checkout.
     *
     * @return void
     */
    public function validate_checkout_tax_fields() {
        if ( ! class_exists( 'Contifico_WooCommerce_Tax_Helper' ) ) {
            return;
        }

        $tax_type      = isset( $_POST['tax_type'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_type'] ) ) : '';
        $tax_id        = isset( $_POST['tax_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_id'] ) ) : '';
        $taxpayer_type = isset( $_POST['taxpayer_type'] ) ? sanitize_text_field( wp_unslash( $_POST['taxpayer_type'] ) ) : '';
        $tax_subject   = isset( $_POST['tax_subject'] ) ? Contifico_WooCommerce_Tax_Helper::sanitize_subject_value( wp_unslash( $_POST['tax_subject'] ) ) : '';
        $company_name  = isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '';
        $country       = isset( $_POST['billing_country'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) ) : '';

        $types = array_keys( Contifico_WooCommerce_Tax_Helper::get_tax_types() );
        if ( ! in_array( $tax_type, $types, true ) ) {
            wc_add_notice( __( 'Selecciona un tipo de identificación válido.', 'contifico-woocommerce' ), 'error' );

            return;
        }

        $taxpayer_types = array_keys( Contifico_WooCommerce_Tax_Helper::get_taxpayer_types() );
        if ( ! in_array( $taxpayer_type, $taxpayer_types, true ) ) {
            wc_add_notice( __( 'Selecciona un tipo de contribuyente válido.', 'contifico-woocommerce' ), 'error' );

            return;
        }

        $validation = Contifico_WooCommerce_Tax_Helper::validate_tax_id( $tax_type, $tax_id );

        if ( is_wp_error( $validation ) ) {
            wc_add_notice( $validation->get_error_message(), 'error' );
        }

        if ( Contifico_WooCommerce_Tax_Helper::is_company_subject( $tax_subject ) && '' === $company_name ) {
            wc_add_notice( __( 'Para emitir el documento a nombre de la empresa debes indicar el nombre de la compañía.', 'contifico-woocommerce' ), 'error' );
        }

        if ( 'ec' === $country && 'pasaporte' === $tax_type ) {
            wc_add_notice( __( 'No es posible utilizar pasaporte como identificación para Ecuador.', 'contifico-woocommerce' ), 'error' );
        }
    }

    /**
     * Almacena los metadatos tributarios en el pedido.
     *
     * @param int $order_id Identificador del pedido.
     *
     * @return void
     */
    public function save_order_tax_fields( $order_id ) {
        if ( ! class_exists( 'Contifico_WooCommerce_Tax_Helper' ) ) {
            return;
        }

        $tax_subject   = isset( $_POST['tax_subject'] ) ? Contifico_WooCommerce_Tax_Helper::sanitize_subject_value( wp_unslash( $_POST['tax_subject'] ) ) : '';
        $tax_type      = isset( $_POST['tax_type'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_type'] ) ) : '';
        $tax_id        = isset( $_POST['tax_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_id'] ) ) : '';
        $taxpayer_type = isset( $_POST['taxpayer_type'] ) ? sanitize_text_field( wp_unslash( $_POST['taxpayer_type'] ) ) : '';

        update_post_meta( $order_id, '_billing_tax_subject', $tax_subject );
        update_post_meta( $order_id, '_billing_tax_type', $tax_type );
        update_post_meta( $order_id, '_billing_tax_id', $tax_id );
        update_post_meta( $order_id, '_billing_taxpayer_type', $taxpayer_type );

        $user_id = get_post_meta( $order_id, '_customer_user', true );

        if ( $user_id ) {
            update_user_meta( $user_id, 'tax_subject', $tax_subject );
            update_user_meta( $user_id, 'tax_type', $tax_type );
            update_user_meta( $user_id, 'tax_id', $tax_id );
            update_user_meta( $user_id, 'taxpayer_type', $taxpayer_type );
        }
    }

    /**
     * Muestra los campos tributarios en la página de cuenta del cliente.
     *
     * @return void
     */
    public function render_account_tax_fields() {
        if ( ! class_exists( 'Contifico_WooCommerce_Tax_Helper' ) ) {
            return;
        }

        $fields = Contifico_WooCommerce_Tax_Helper::get_account_fields( false );

        foreach ( $fields as $key => $field_args ) {
            $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ( isset( $field_args['value'] ) ? $field_args['value'] : '' );

            if ( isset( $field_args['value'] ) ) {
                unset( $field_args['value'] );
            }

            woocommerce_form_field( $key, $field_args, $value );
        }
    }

    /**
     * Guarda la información tributaria desde el formulario de cuenta o perfil.
     *
     * @param int $user_id Identificador del usuario.
     *
     * @return void
     */
    public function save_account_details_fields( $user_id ) {
        if ( ! $user_id || ! class_exists( 'Contifico_WooCommerce_Tax_Helper' ) ) {
            return;
        }

        $fields = Contifico_WooCommerce_Tax_Helper::get_account_fields( false, $user_id );

        foreach ( $fields as $key => $field_args ) {
            $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

            if ( 'tax_subject' === $key ) {
                $value = Contifico_WooCommerce_Tax_Helper::sanitize_subject_value( $value );
            } else {
                $value = sanitize_text_field( $value );
            }

            update_user_meta( $user_id, $key, $value );
        }
    }
}
