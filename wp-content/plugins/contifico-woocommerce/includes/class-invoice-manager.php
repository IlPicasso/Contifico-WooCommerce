<?php
/**
 * Gestor responsable de crear documentos electrónicos en Contifico.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orquesta la generación de facturas o pre-facturas desde WooCommerce.
 */
class Contifico_WooCommerce_Invoice_Manager {

    const META_INVOICE_ID     = '_contifico_invoice_id';
    const META_INVOICE_NUMBER = '_contifico_invoice_number';

    /**
     * Instancia del manejador de ajustes.
     *
     * @var Contifico_WooCommerce_Admin_Settings
     */
    protected $settings;

    /**
     * Cliente HTTP hacia la API de Contifico.
     *
     * @var Contifico_WooCommerce_Api_Contifico_Client
     */
    protected $client;

    /**
     * Logger opcional para depuración.
     *
     * @var WC_Logger|null
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Contifico_WooCommerce_Admin_Settings        $settings Gestor de ajustes.
     * @param Contifico_WooCommerce_Api_Contifico_Client  $client   Cliente HTTP.
     * @param WC_Logger|null                              $logger   Logger opcional.
     */
    public function __construct( Contifico_WooCommerce_Admin_Settings $settings, Contifico_WooCommerce_Api_Contifico_Client $client, $logger = null ) {
        $this->settings = $settings;
        $this->client   = $client;
        $this->logger   = $logger;
    }

    /**
     * Registra los hooks necesarios para procesar facturas.
     *
     * @return void
     */
    public function init_hooks() {
        add_filter( 'woocommerce_order_actions', array( $this, 'register_order_action' ) );
        add_action( 'woocommerce_order_action_contifico_generate_invoice', array( $this, 'handle_manual_action' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_trigger_for_status' ), 10, 4 );
    }

    /**
     * Añade la opción de generar la factura desde la pantalla de pedidos.
     *
     * @param array $actions Acciones disponibles.
     *
     * @return array
     */
    public function register_order_action( $actions ) {
        $actions['contifico_generate_invoice'] = __( 'Generar documento en Contifico', 'contifico-woocommerce' );

        return $actions;
    }

    /**
     * Ejecuta la generación manual de la factura.
     *
     * @param mixed $order Pedido seleccionado.
     *
     * @return void
     */
    public function handle_manual_action( $order ) {
        $this->generate_invoice( $order, true );
    }

    /**
     * Comprueba si el cambio de estado debe generar un documento automático.
     *
     * @param int       $order_id   Identificador del pedido.
     * @param string    $old_status Estado anterior.
     * @param string    $new_status Nuevo estado.
     * @param WC_Order  $order      Instancia del pedido.
     *
     * @return void
     */
    public function maybe_trigger_for_status( $order_id, $old_status, $new_status, $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings = $this->settings->get_settings();

        if ( 'yes' !== $settings['invoice_enabled'] ) {
            return;
        }

        $target_status = isset( $settings['invoice_trigger_status'] ) ? $settings['invoice_trigger_status'] : '';
        if ( '' === $target_status ) {
            return;
        }

        $normalized_target = $this->normalize_status_key( $target_status );

        if ( $normalized_target === $new_status ) {
            $this->generate_invoice( $order, false );
        }
    }

    /**
     * Construye y envía la solicitud para crear el documento.
     *
     * @param mixed $order Pedido o identificador de pedido.
     * @param bool  $force Permite regenerar documentos existentes.
     *
     * @return void
     */
    public function generate_invoice( $order, $force = false ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings = $this->settings->get_settings();

        if ( ! $force && 'yes' !== $settings['invoice_enabled'] ) {
            return;
        }

        if ( $order->get_total() <= 0 ) {
            $this->add_order_note( $order, __( 'No se generó el documento porque el pedido tiene un total igual a cero.', 'contifico-woocommerce' ) );

            return;
        }

        $existing_id = $order->get_meta( self::META_INVOICE_ID );
        if ( ! $force && ! empty( $existing_id ) ) {
            $this->add_order_note( $order, __( 'Ya existe un documento generado para este pedido.', 'contifico-woocommerce' ) );

            return;
        }

        $payload = $this->build_invoice_payload( $order, $settings );

        if ( is_wp_error( $payload ) ) {
            $this->add_order_note( $order, $payload->get_error_message() );

            return;
        }

        $response = $this->client->create_invoice( $payload );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', $response->get_error_message(), array( 'order_id' => $order->get_id() ) );
            $this->add_order_note( $order, sprintf( __( 'No fue posible generar el documento en Contifico: %s', 'contifico-woocommerce' ), $response->get_error_message() ) );

            return;
        }

        if ( isset( $response['id'] ) ) {
            $order->update_meta_data( self::META_INVOICE_ID, $response['id'] );
            if ( isset( $response['documento'] ) ) {
                $order->update_meta_data( self::META_INVOICE_NUMBER, $response['documento'] );
            }

            $order->save();

            $document_number = isset( $response['documento'] ) ? $response['documento'] : '';
            if ( '' !== $document_number ) {
                $this->add_order_note( $order, sprintf( __( 'Documento generado en Contifico con número %s.', 'contifico-woocommerce' ), $document_number ) );
            } else {
                $this->add_order_note( $order, __( 'El documento fue generado correctamente en Contifico.', 'contifico-woocommerce' ) );
            }

            if ( 'FAC' === $settings['invoice_document_type'] ) {
                $this->increment_sequential( $settings );
            }
        } else {
            $this->add_order_note( $order, __( 'No se recibió un identificador válido del documento generado en Contifico.', 'contifico-woocommerce' ) );
        }
    }

    /**
     * Construye la carga útil que se enviará a Contifico.
     *
     * @param WC_Order $order    Pedido a procesar.
     * @param array    $settings Configuración actual.
     *
     * @return array|WP_Error
     */
    protected function build_invoice_payload( WC_Order $order, array $settings ) {
        if ( ! class_exists( 'Contifico_WooCommerce_Tax_Helper' ) ) {
            return new WP_Error( 'contifico_missing_helper', __( 'No se pudo preparar la información fiscal requerida.', 'contifico-woocommerce' ) );
        }

        $environment = isset( $settings['invoice_environment'] ) ? $settings['invoice_environment'] : 'test';
        $environment = in_array( $environment, array( 'test', 'production' ), true ) ? $environment : 'test';

        $pos_token = isset( $settings[ "invoice_{$environment}_pos_token" ] ) ? $settings[ "invoice_{$environment}_pos_token" ] : '';

        if ( '' === $pos_token ) {
            return new WP_Error( 'contifico_missing_pos_token', __( 'Configura el token del punto de venta para emitir documentos en Contifico.', 'contifico-woocommerce' ) );
        }

        $document_type = isset( $settings['invoice_document_type'] ) ? $settings['invoice_document_type'] : 'FAC';

        $date     = current_time( 'timestamp' );
        $document = $this->build_document_number( $order, $settings, $environment );

        if ( is_wp_error( $document ) ) {
            return $document;
        }

        $tax_type      = $order->get_meta( '_billing_tax_type' );
        $tax_id        = $order->get_meta( '_billing_tax_id' );
        $taxpayer_type = $order->get_meta( '_billing_taxpayer_type' );
        $tax_subject   = Contifico_WooCommerce_Tax_Helper::sanitize_subject_value( $order->get_meta( '_billing_tax_subject' ) );
        $company_name  = trim( (string) $order->get_billing_company() );
        $normalized_tax_type = strtolower( (string) $tax_type );
        $billing_country     = strtolower( (string) $order->get_billing_country() );

        $validation = Contifico_WooCommerce_Tax_Helper::validate_tax_id( $normalized_tax_type, $tax_id );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        if ( Contifico_WooCommerce_Tax_Helper::is_company_subject( $tax_subject ) && '' === $company_name ) {
            return new WP_Error(
                'contifico_missing_company_name',
                __( 'Para emitir el documento a nombre de la empresa debes indicar el nombre de la compañía.', 'contifico-woocommerce' )
            );
        }

        if ( in_array( $billing_country, array( 'ec', 'ecuador' ), true ) ) {
            if ( 'pasaporte' === $normalized_tax_type ) {
                return new WP_Error(
                    'contifico_invalid_passport_for_ecuador',
                    __( 'No es posible utilizar pasaporte como identificación para Ecuador.', 'contifico-woocommerce' )
                );
            }
        } elseif ( '' !== $billing_country && 'exterior' !== $normalized_tax_type ) {
            return new WP_Error(
                'contifico_invalid_foreign_tax_type',
                __( 'Para emitir documentos fuera de Ecuador selecciona el tipo de identificación "exterior".', 'contifico-woocommerce' )
            );
        }

        $customer_name = Contifico_WooCommerce_Tax_Helper::is_company_subject( $tax_subject ) && '' !== $company_name
            ? $company_name
            : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        if ( '' === $customer_name ) {
            $customer_name = $order->get_billing_email();
        }

        $cliente = array(
            $normalized_tax_type                         => $tax_id,
            'razon_social'                               => $customer_name,
            'telefonos'                                  => $order->get_billing_phone(),
            'direccion'                                  => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
            'tipo'                                       => $taxpayer_type ? $taxpayer_type : 'N',
            'email'                                      => $order->get_billing_email(),
            'es_extranjero'                              => $this->is_foreign_customer( $order, $tax_type ),
        );

        $vendor = $this->build_vendor_data( $settings );
        if ( is_wp_error( $vendor ) ) {
            return $vendor;
        }

        $items_payload = $this->build_items_payload( $order, $settings, $environment, $cliente['es_extranjero'] );
        if ( is_wp_error( $items_payload ) ) {
            return $items_payload;
        }

        $payload = array(
            'pos'            => $pos_token,
            'fecha_emision'  => gmdate( 'd/m/Y', $date ),
            'tipo_documento' => $document_type,
            'documento'      => $document,
            'estado'         => 'P',
            'caja_id'        => null,
            'cliente'        => $cliente,
            'vendedor'       => $vendor,
            'descripcion'    => sprintf( __( 'Pedido #%d desde WooCommerce', 'contifico-woocommerce' ), $order->get_id() ),
            'subtotal_0'     => $items_payload['subtotal_0'],
            'subtotal_12'    => $items_payload['subtotal_taxed'],
            'iva'            => $items_payload['tax_total'],
            'servicio'       => 0,
            'total'          => round( $order->get_total(), 2 ),
            'adicional1'     => '',
            'adicional2'     => '',
            'detalles'       => $items_payload['items'],
        );

        if ( 'FAC' === $document_type ) {
            $payload = array_merge(
                $payload,
                array(
                    'electronico'  => true,
                    'autorizacion' => '',
                    'lote'         => gmdate( 'Ymd', $date ),
                    'cobros'       => array( $this->build_payment_payload( $order, $payload['total'], $date ) ),
                )
            );
        }

        return $payload;
    }

    /**
     * Construye los datos del vendedor emisor.
     *
     * @param array $settings Ajustes del plugin.
     *
     * @return array|WP_Error
     */
    protected function build_vendor_data( array $settings ) {
        $required = array( 'invoice_sender_tax_id', 'invoice_sender_name' );

        foreach ( $required as $key ) {
            if ( empty( $settings[ $key ] ) ) {
                return new WP_Error( 'contifico_missing_vendor_data', __( 'Completa los datos del emisor para generar documentos electrónicos.', 'contifico-woocommerce' ) );
            }
        }

        return array(
            'ruc'           => $settings['invoice_sender_tax_id'],
            'razon_social'  => $settings['invoice_sender_name'],
            'telefonos'     => $settings['invoice_sender_phone'],
            'direccion'     => $settings['invoice_sender_address'],
            'tipo'          => isset( $settings['invoice_sender_taxpayer_type'] ) ? $settings['invoice_sender_taxpayer_type'] : 'N',
            'email'         => isset( $settings['invoice_sender_email'] ) ? $settings['invoice_sender_email'] : '',
            'es_extranjero' => false,
        );
    }

    /**
     * Arma la sección de detalles del documento y calcula subtotales.
     *
     * @param WC_Order $order          Pedido a procesar.
     * @param array    $settings       Ajustes actuales.
     * @param string   $environment    Ambiente activo.
     * @param bool     $foreign_client Indica si el cliente es extranjero.
     *
     * @return array|WP_Error
     */
    protected function build_items_payload( WC_Order $order, array $settings, $environment, $foreign_client ) {
        $items          = array();
        $subtotal_taxed = 0;
        $subtotal_0     = 0;
        $subtotal_free  = 0;
        $tax_total      = 0;

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $result = $this->build_item_payload( $item, $environment, $foreign_client );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $items[]        = $result['payload'];
            $subtotal_taxed += $result['base_gravable'];
            $subtotal_0     += $result['base_cero'];
            $subtotal_free  += $result['base_no_gravable'];
            $tax_total      += $result['iva'];
        }

        $shipping = $this->build_shipping_payload( $order, $settings, $environment, $foreign_client );

        if ( is_wp_error( $shipping ) ) {
            return $shipping;
        }

        if ( ! empty( $shipping ) ) {
            $items[]        = $shipping['payload'];
            $subtotal_taxed += $shipping['base_gravable'];
            $subtotal_0     += $shipping['base_cero'];
            $subtotal_free  += $shipping['base_no_gravable'];
            $tax_total      += $shipping['iva'];
        }

        if ( empty( $items ) ) {
            return new WP_Error( 'contifico_empty_invoice', __( 'No hay líneas de pedido válidas para generar el documento electrónico.', 'contifico-woocommerce' ) );
        }

        return array(
            'items'          => $items,
            'subtotal_taxed' => round( $subtotal_taxed, 2 ),
            'subtotal_0'     => round( $subtotal_0, 2 ),
            'subtotal_free'  => round( $subtotal_free, 2 ),
            'tax_total'      => round( $tax_total, 2 ),
        );
    }

    /**
     * Genera el payload para un ítem del pedido.
     *
     * @param WC_Order_Item_Product $item            Línea de pedido.
     * @param string                $environment     Ambiente actual.
     * @param bool                  $foreign_client  Si el cliente es extranjero.
     *
     * @return array|WP_Error
     */
    protected function build_item_payload( WC_Order_Item_Product $item, $environment, $foreign_client ) {
        $product = $item->get_product();

        if ( ! $product instanceof WC_Product ) {
            return new WP_Error( 'contifico_missing_product', __( 'No se pudo obtener la información del producto para generar el documento.', 'contifico-woocommerce' ) );
        }

        $quantity      = $item->get_quantity() > 0 ? (float) $item->get_quantity() : 1;
        $line_total    = (float) $item->get_total();
        $subtotal      = (float) $item->get_subtotal();
        $tax_total     = (float) $item->get_total_tax();
        $unit_price    = round( $line_total / $quantity, 2 );
        $discount_rate = 0;

        if ( $subtotal > 0 && $subtotal !== $line_total ) {
            $discount_rate = max( 0, ( ( $subtotal - $line_total ) / $subtotal ) * 100 );
        }

        $porcentaje_iva = ( $tax_total > 0 && $line_total > 0 ) ? round( ( $tax_total / $line_total ) * 100, 2 ) : 0;

        $base_cero       = 0;
        $base_gravable   = 0;
        $base_no_gravable = 0;

        if ( $foreign_client ) {
            $base_no_gravable = $line_total;
        } elseif ( $tax_total > 0 ) {
            $base_gravable = $line_total;
        } else {
            $base_cero = $line_total;
        }

        $contifico_product_id = $this->resolve_contifico_product_id( $product );

        return array(
            'payload' => array(
                'producto_id'          => $contifico_product_id,
                'cantidad'             => round( $quantity, 2 ),
                'precio'               => $unit_price,
                'porcentaje_iva'       => $porcentaje_iva,
                'porcentaje_descuento' => round( $discount_rate, 2 ),
                'base_cero'            => round( $base_cero, 2 ),
                'base_gravable'        => round( $base_gravable, 2 ),
                'base_no_gravable'     => round( $base_no_gravable, 2 ),
            ),
            'base_cero'        => $base_cero,
            'base_gravable'    => $base_gravable,
            'base_no_gravable' => $base_no_gravable,
            'iva'              => $tax_total,
        );
    }

    /**
     * Construye la línea de envío si corresponde.
     *
     * @param WC_Order $order          Pedido a procesar.
     * @param array    $settings       Ajustes configurados.
     * @param string   $environment    Ambiente actual.
     * @param bool     $foreign_client Si el cliente es extranjero.
     *
     * @return array|WP_Error
     */
    protected function build_shipping_payload( WC_Order $order, array $settings, $environment, $foreign_client ) {
        $shipping_total = (float) $order->get_shipping_total();

        if ( $shipping_total <= 0 ) {
            return array();
        }

        $shipping_code = isset( $settings['invoice_shipping_code'] ) ? $settings['invoice_shipping_code'] : '';

        if ( '' === $shipping_code ) {
            return new WP_Error( 'contifico_missing_shipping_code', __( 'Configura el SKU del producto de envío para registrar cargos de transporte.', 'contifico-woocommerce' ) );
        }

        $product_id = $this->resolve_contifico_product_id_by_sku( $shipping_code );

        $shipping_tax = (float) $order->get_shipping_tax();
        $porcentaje_iva = ( $shipping_tax > 0 && $shipping_total > 0 ) ? round( ( $shipping_tax / $shipping_total ) * 100, 2 ) : 0;

        $base_cero        = 0;
        $base_gravable    = 0;
        $base_no_gravable = 0;

        if ( $foreign_client ) {
            $base_no_gravable = $shipping_total;
        } elseif ( $shipping_tax > 0 ) {
            $base_gravable = $shipping_total;
        } else {
            $base_cero = $shipping_total;
        }

        return array(
            'payload' => array(
                'producto_id'          => $product_id,
                'cantidad'             => 1,
                'precio'               => round( $shipping_total, 2 ),
                'porcentaje_iva'       => $porcentaje_iva,
                'porcentaje_descuento' => 0,
                'base_cero'            => round( $base_cero, 2 ),
                'base_gravable'        => round( $base_gravable, 2 ),
                'base_no_gravable'     => round( $base_no_gravable, 2 ),
            ),
            'base_cero'        => $base_cero,
            'base_gravable'    => $base_gravable,
            'base_no_gravable' => $base_no_gravable,
            'iva'              => $shipping_tax,
        );
    }

    /**
     * Determina el identificador de producto en Contifico.
     *
     * @param WC_Product $product Producto de WooCommerce.
     *
     * @return string
     */
    protected function resolve_contifico_product_id( WC_Product $product ) {
        $meta_key = '_contifico_product_id';
        $stored   = get_post_meta( $product->get_id(), $meta_key, true );

        if ( '' !== $stored ) {
            return (string) $stored;
        }

        $sku = $product->get_sku();
        if ( '' === $sku ) {
            return '';
        }

        $resolved = $this->resolve_contifico_product_id_by_sku( $sku );

        if ( '' !== $resolved ) {
            update_post_meta( $product->get_id(), $meta_key, $resolved );
        }

        return (string) $resolved;
    }

    /**
     * Busca un producto en Contifico utilizando el SKU proporcionado.
     *
     * @param string $sku SKU del producto.
     *
     * @return string
     */
    protected function resolve_contifico_product_id_by_sku( $sku ) {
        $response = $this->client->get_items( array( 'codigo' => $sku ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'warning', $response->get_error_message(), array( 'sku' => $sku ) );

            return '';
        }

        $data = array();
        if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
            $data = $response['results'];
        } elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $data = $response['data'];
        } elseif ( is_array( $response ) ) {
            $data = $response;
        }

        foreach ( $data as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $possible_id = '';

            foreach ( array( 'id', 'producto_id', 'product_id' ) as $key ) {
                if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                    $possible_id = (string) $item[ $key ];
                    break;
                }
            }

            if ( '' === $possible_id ) {
                continue;
            }

            if ( isset( $item['codigo'] ) && (string) $item['codigo'] !== $sku ) {
                continue;
            }

            if ( isset( $item['sku'] ) && '' !== $item['sku'] && (string) $item['sku'] !== $sku ) {
                continue;
            }

            return $possible_id;
        }

        return '';
    }

    /**
     * Calcula el número de documento según el tipo configurado.
     *
     * @param WC_Order $order       Pedido.
     * @param array    $settings    Ajustes del plugin.
     * @param string   $environment Ambiente actual.
     *
     * @return string|WP_Error
     */
    protected function build_document_number( WC_Order $order, array $settings, $environment ) {
        $type = isset( $settings['invoice_document_type'] ) ? $settings['invoice_document_type'] : 'FAC';

        if ( 'FAC' !== $type ) {
            return gmdate( 'Ymd' ) . $order->get_id();
        }

        $establishment = isset( $settings[ "invoice_{$environment}_establishment" ] ) ? $settings[ "invoice_{$environment}_establishment" ] : '';
        $emission      = isset( $settings[ "invoice_{$environment}_emission_point" ] ) ? $settings[ "invoice_{$environment}_emission_point" ] : '';
        $next_number   = isset( $settings[ "invoice_{$environment}_next_number" ] ) ? $settings[ "invoice_{$environment}_next_number" ] : '';

        if ( '' === $establishment || '' === $emission || '' === $next_number ) {
            return new WP_Error( 'contifico_missing_sequential', __( 'Configura el establecimiento, punto de emisión y secuencial para generar facturas.', 'contifico-woocommerce' ) );
        }

        $numeric = preg_replace( '/\D/', '', (string) $next_number );
        $numeric = '' === $numeric ? '1' : $numeric;
        $number  = str_pad( $numeric, 9, '0', STR_PAD_LEFT );

        return sprintf( '%s-%s-%s', $establishment, $emission, $number );
    }

    /**
     * Prepara la información del cobro asociado al pedido.
     *
     * @param WC_Order $order Pedido a procesar.
     * @param float    $total Total del documento.
     * @param int      $date  Marca de tiempo actual.
     *
     * @return array
     */
    protected function build_payment_payload( WC_Order $order, $total, $date ) {
        $map = array(
            'cod'    => array( 'forma_cobro' => 'EF' ),
            'bacs'   => array( 'forma_cobro' => 'TRA' ),
            'cheque' => array( 'forma_cobro' => 'CH', 'numero_cheque' => 'S/N' ),
        );

        $payment_method = $order->get_payment_method();
        $base_payload   = isset( $map[ $payment_method ] ) ? $map[ $payment_method ] : array( 'forma_cobro' => 'TC', 'tipo_ping' => 'D' );

        return array_merge(
            $base_payload,
            array(
                'monto' => round( $total, 2 ),
                'fecha' => gmdate( 'd/m/Y', $date ),
            )
        );
    }

    /**
     * Determina si el cliente debe tratarse como extranjero.
     *
     * @param WC_Order $order    Pedido a revisar.
     * @param string   $tax_type Tipo de identificación.
     *
     * @return bool
     */
    protected function is_foreign_customer( WC_Order $order, $tax_type ) {
        $country = strtolower( (string) $order->get_billing_country() );

        if ( 'exterior' === strtolower( (string) $tax_type ) ) {
            return true;
        }

        return 'ec' !== $country && 'ecuador' !== $country;
    }

    /**
     * Incrementa el secuencial configurado para el ambiente actual.
     *
     * @param array $settings Ajustes actuales.
     *
     * @return void
     */
    protected function increment_sequential( array $settings ) {
        $environment = isset( $settings['invoice_environment'] ) ? $settings['invoice_environment'] : 'test';
        $environment = in_array( $environment, array( 'test', 'production' ), true ) ? $environment : 'test';

        $key      = "invoice_{$environment}_next_number";
        $current  = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
        $numeric  = preg_replace( '/\D/', '', (string) $current );
        $next_int = '' === $numeric ? 1 : absint( $numeric ) + 1;

        $this->settings->update_settings( array( $key => (string) $next_int ) );
    }

    /**
     * Añade una nota al pedido.
     *
     * @param WC_Order $order Pedido.
     * @param string   $message Mensaje a registrar.
     *
     * @return void
     */
    protected function add_order_note( WC_Order $order, $message ) {
        if ( '' === $message ) {
            return;
        }

        $order->add_order_note( wp_kses_post( $message ) );
    }

    /**
     * Normaliza la clave de estado para comparaciones.
     *
     * @param string $status Estado a normalizar.
     *
     * @return string
     */
    protected function normalize_status_key( $status ) {
        $status = (string) $status;

        if ( 0 === strpos( $status, 'wc-' ) ) {
            $status = substr( $status, 3 );
        }

        return $status;
    }

    /**
     * Registra eventos en el logger configurado.
     *
     * @param string $level   Nivel de severidad.
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     *
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
        if ( ! $this->logger instanceof WC_Logger ) {
            return;
        }

        $this->logger->log( $level, $message, array_merge( array( 'source' => Contifico_WooCommerce_Api_Contifico_Client::LOG_SOURCE ), $context ) );
    }
}
