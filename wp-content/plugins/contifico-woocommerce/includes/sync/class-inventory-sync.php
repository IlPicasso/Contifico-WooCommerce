<?php
/**
 * Sincronización de inventario con Contifico.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestiona la sincronización de inventario entre Contifico y WooCommerce.
 */
class Contifico_WooCommerce_Sync_Inventory_Sync {

    /**
     * Meta key donde se almacena la información de stock por bodega.
     */
    const META_KEY = '_contifico_stock_by_warehouse';

    /**
     * Nombre del hook utilizado por WP Cron para ejecutar la sincronización.
     */
    const CRON_HOOK = 'contifico_woocommerce_inventory_sync';

    /**
     * Option donde se guarda la bitácora del último proceso de sincronización.
     */
    const LOG_OPTION = 'contifico_woocommerce_inventory_log';

    /**
     * Namespace de los endpoints REST del plugin.
     */
    const REST_NAMESPACE = 'contifico-woocommerce/v1';

    /**
     * Ruta del endpoint que fuerza la sincronización de inventario.
     */
    const REST_ROUTE = '/inventory/sync';

    /**
     * Prefijo utilizado para almacenar notificaciones temporales.
     */
    const NOTICE_TRANSIENT_PREFIX = 'contifico_inventory_sync_notice_';

    /**
     * Cliente HTTP para comunicarse con Contifico.
     *
     * @var Contifico_WooCommerce_Api_Contifico_Client|null
     */
    protected $client;

    /**
     * Instancia del logger.
     *
     * @var WC_Logger|null
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Contifico_WooCommerce_Api_Contifico_Client|null $client Cliente HTTP.
     * @param WC_Logger|null                                  $logger Logger personalizado.
     */
    public function __construct( ?Contifico_WooCommerce_Api_Contifico_Client $client = null, $logger = null ) {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Registra los hooks necesarios para la sincronización.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'init', array( $this, 'schedule_cron' ) );
        add_action( self::CRON_HOOK, array( $this, 'handle_scheduled_sync' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_post_contifico_inventory_sync', array( $this, 'handle_admin_post_sync' ) );
    }

    /**
     * Programa la tarea recurrente que consulta el inventario en Contifico.
     *
     * @return void
     */
    public function schedule_cron() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        $interval = apply_filters( 'contifico_woocommerce_inventory_sync_interval', 'hourly' );

        wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK );
    }

    /**
     * Ejecuta la sincronización cuando es disparada por WP Cron.
     *
     * @return void
     */
    public function handle_scheduled_sync() {
        $this->sync_inventory( 'scheduled' );
    }

    /**
     * Gestiona la solicitud enviada desde el formulario de ajustes.
     *
     * @return void
     */
    public function handle_admin_post_sync() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos para ejecutar esta acción.', 'contifico-woocommerce' ) );
        }

        check_admin_referer( 'contifico_inventory_sync' );

        $result = $this->sync_inventory( 'manual' );

        $status  = is_wp_error( $result ) ? 'error' : 'success';
        $message = is_wp_error( $result )
            ? $result->get_error_message()
            : ( isset( $result['message'] ) ? $result['message'] : esc_html__( 'La sincronización se ejecutó correctamente.', 'contifico-woocommerce' ) );

        $this->store_notice_for_current_user( $status, $message );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => 'contifico-woocommerce',
                    'inventory-sync'  => $status,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Registra el endpoint REST para forzar la sincronización.
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => array( $this, 'rest_permissions_check' ),
                'callback'            => array( $this, 'handle_rest_sync' ),
            )
        );
    }

    /**
     * Verifica los permisos antes de ejecutar el endpoint.
     *
     * @return bool
     */
    public function rest_permissions_check() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Maneja la solicitud REST para sincronizar el inventario.
     *
     * @return WP_REST_Response
     */
    public function handle_rest_sync() {
        $result = $this->sync_inventory( 'rest' );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ),
                500
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => $result['message'],
                'data'    => isset( $result['summary'] ) ? $result['summary'] : array(),
            )
        );
    }

    /**
     * Realiza la sincronización de inventario con Contifico.
     *
     * @param string $context Contexto desde el cual se ejecuta la sincronización.
     *
     * @return array|WP_Error
     */
    public function sync_inventory( $context = 'manual' ) {
        $client = $this->get_client();

        if ( ! $client ) {
            $error = new WP_Error(
                'contifico_missing_client',
                esc_html__( 'No fue posible inicializar el cliente de Contifico.', 'contifico-woocommerce' )
            );

            $this->store_log( 'error', $error->get_error_message(), array( 'context' => $context ) );

            return $error;
        }

        $response = $client->get_warehouses();

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', $response->get_error_message(), array( 'context' => $context ) );
            $this->store_log(
                'error',
                $response->get_error_message(),
                array(
                    'context'    => $context,
                    'error_code' => $response->get_error_code(),
                )
            );

            return $response;
        }

        $warehouses = $this->normalize_warehouses( $response );

        $summary = $this->process_inventory_response( $warehouses );

        if ( ! empty( $summary['errors'] ) ) {
            foreach ( $summary['errors'] as $error_message ) {
                $this->log( 'warning', $error_message, array( 'context' => $context ) );
            }
        }

        $message = sprintf(
            /* translators: 1: context label, 2: warehouse count, 3: product count */
            esc_html__( 'Sincronización de inventario %1$s completada. Bodegas procesadas: %2$d. Productos actualizados: %3$d.', 'contifico-woocommerce' ),
            $this->get_context_label( $context ),
            isset( $summary['warehouses'] ) ? (int) $summary['warehouses'] : 0,
            isset( $summary['products_updated'] ) ? (int) $summary['products_updated'] : 0
        );

        $log = $this->store_log(
            'success',
            $message,
            array_merge(
                $summary,
                array(
                    'context' => $context,
                )
            )
        );

        $this->log( 'info', $message, array( 'context' => $context ) );

        return array(
            'message' => $message,
            'summary' => $summary,
            'log'     => $log,
        );
    }

    /**
     * Procesa la información devuelta por la API y actualiza el stock de productos.
     *
     * @param array $warehouses Listado de bodegas.
     *
     * @return array
     */
    protected function process_inventory_response( array $warehouses ) {
        $existing_inventory = $this->get_products_with_inventory_meta();
        $grouped_inventory  = array();
        $updated_products   = array();
        $errors             = array();
        $warehouses_count   = 0;
        $items_processed    = 0;

        foreach ( $warehouses as $warehouse ) {
            $warehouse_id = $this->get_warehouse_id( $warehouse );

            if ( '' === $warehouse_id ) {
                $errors[] = esc_html__( 'Se omitió una bodega sin identificador válido.', 'contifico-woocommerce' );
                continue;
            }

            $warehouses_count++;

            $warehouse_name = $this->get_warehouse_name( $warehouse, $warehouse_id );
            $items          = $this->extract_items_from_warehouse( $warehouse );

            if ( empty( $items ) ) {
                $errors[] = sprintf(
                    /* translators: %s warehouse identifier */
                    esc_html__( 'La bodega %s no devolvió información de inventario.', 'contifico-woocommerce' ),
                    esc_html( $warehouse_id )
                );
                continue;
            }

            foreach ( $items as $item ) {
                $items_processed++;

                if ( ! is_array( $item ) ) {
                    $errors[] = sprintf(
                        /* translators: %s warehouse identifier */
                        esc_html__( 'Se encontró un registro de inventario inválido en la bodega %s.', 'contifico-woocommerce' ),
                        esc_html( $warehouse_id )
                    );
                    continue;
                }

                $product_id = $this->resolve_product_id( $item );

                if ( ! $product_id ) {
                    $errors[] = sprintf(
                        /* translators: 1: warehouse identifier, 2: product reference */
                        esc_html__( 'No fue posible asociar el producto %2$s con un producto de WooCommerce en la bodega %1$s.', 'contifico-woocommerce' ),
                        esc_html( $warehouse_id ),
                        esc_html( $this->get_product_reference_from_item( $item ) )
                    );
                    continue;
                }

                $stock = $this->resolve_stock_quantity( $item );

                if ( null === $stock ) {
                    $errors[] = sprintf(
                        /* translators: 1: warehouse identifier, 2: product id */
                        esc_html__( 'No se encontró la cantidad de stock para el producto %2$d en la bodega %1$s.', 'contifico-woocommerce' ),
                        esc_html( $warehouse_id ),
                        (int) $product_id
                    );
                    continue;
                }

                if ( ! isset( $grouped_inventory[ $product_id ] ) ) {
                    $grouped_inventory[ $product_id ] = array();
                }

                $grouped_inventory[ $product_id ][ $warehouse_id ] = array(
                    'warehouse_id'   => $warehouse_id,
                    'name'           => $warehouse_name,
                    'stock'          => $stock,
                    'updated_at_gmt' => current_time( 'mysql', true ),
                );

                $updated_products[ $product_id ] = true;
            }
        }

        $products_in_response = array_map( 'absint', array_keys( $grouped_inventory ) );
        $products_in_response = array_values( array_filter( array_unique( $products_in_response ) ) );

        $stale_products  = array_diff( $existing_inventory, $products_in_response );
        $products_cleaned = 0;

        if ( ! empty( $stale_products ) ) {
            foreach ( $stale_products as $product_id ) {
                $product_id = absint( $product_id );

                if ( ! $product_id ) {
                    continue;
                }

                delete_post_meta( $product_id, self::META_KEY );
                $products_cleaned++;
            }
        }

        foreach ( $grouped_inventory as $product_id => $warehouses_stock ) {
            if ( empty( $warehouses_stock ) ) {
                delete_post_meta( $product_id, self::META_KEY );
                continue;
            }

            update_post_meta( $product_id, self::META_KEY, $warehouses_stock );
        }

        return array(
            'warehouses'       => $warehouses_count,
            'products_updated' => count( $updated_products ),
            'items_processed'  => $items_processed,
            'products_cleaned' => $products_cleaned,
            'errors'           => $errors,
        );
    }

    /**
     * Obtiene el listado de productos que actualmente almacenan el meta de inventario.
     *
     * @return array
     */
    protected function get_products_with_inventory_meta() {
        if ( ! function_exists( 'get_posts' ) ) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type'      => array( 'product', 'product_variation' ),
                'meta_key'       => self::META_KEY,
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'no_found_rows'  => true,
                'suppress_filters' => true,
            )
        );

        if ( ! is_array( $posts ) ) {
            return array();
        }

        $posts = array_map( 'absint', $posts );

        return array_values( array_filter( array_unique( $posts ) ) );
    }

    /**
     * Extrae los registros de inventario contenidos en la información de la bodega.
     *
     * @param array $warehouse Datos de la bodega.
     *
     * @return array
     */
    protected function extract_items_from_warehouse( array $warehouse ) {
        $keys = array( 'productos', 'items', 'inventory', 'detalles', 'existencias', 'stock' );

        foreach ( $keys as $key ) {
            if ( isset( $warehouse[ $key ] ) && is_array( $warehouse[ $key ] ) ) {
                return $warehouse[ $key ];
            }
        }

        // Algunos endpoints pueden devolver la información directamente como listado sin claves.
        if ( $this->is_list( $warehouse ) ) {
            return $warehouse;
        }

        return array();
    }

    /**
     * Obtiene el identificador de una bodega.
     *
     * @param array $warehouse Datos crudos de la bodega.
     *
     * @return string
     */
    protected function get_warehouse_id( array $warehouse ) {
        $keys = array( 'id', 'bodega_id', 'codigo', 'codigo_principal' );

        foreach ( $keys as $key ) {
            if ( isset( $warehouse[ $key ] ) && '' !== $warehouse[ $key ] ) {
                return (string) $warehouse[ $key ];
            }
        }

        return '';
    }

    /**
     * Obtiene el nombre descriptivo de la bodega.
     *
     * @param array  $warehouse    Datos de la bodega.
     * @param string $fallback_id  Identificador utilizado como respaldo.
     *
     * @return string
     */
    protected function get_warehouse_name( array $warehouse, $fallback_id ) {
        $keys = array( 'name', 'nombre', 'descripcion', 'descripcion_bodega' );

        foreach ( $keys as $key ) {
            if ( isset( $warehouse[ $key ] ) && '' !== $warehouse[ $key ] ) {
                return (string) $warehouse[ $key ];
            }
        }

        return (string) $fallback_id;
    }

    /**
     * Determina el ID del producto en WooCommerce asociado a la entrada de inventario.
     *
     * @param array $item Datos del inventario en la bodega.
     *
     * @return int
     */
    protected function resolve_product_id( array $item ) {
        $id_keys = array( 'woocommerce_id', 'woo_id', 'product_id', 'producto_id', 'id_producto', 'id' );

        foreach ( $id_keys as $key ) {
            if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                $candidate = absint( $item[ $key ] );

                $post_type = $candidate ? get_post_type( $candidate ) : '';

                if ( $candidate && in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
                    return $candidate;
                }
            }
        }

        $sku_keys = array( 'sku', 'codigo', 'codigo_principal', 'codigo_auxiliar' );

        foreach ( $sku_keys as $key ) {
            if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                $sku = $this->clean( $item[ $key ] );

                if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
                    $product_id = wc_get_product_id_by_sku( $sku );

                    if ( $product_id ) {
                        return $product_id;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Obtiene una representación legible del producto desde los datos del inventario.
     *
     * @param array $item Datos del inventario.
     *
     * @return string
     */
    protected function get_product_reference_from_item( array $item ) {
        $keys = array( 'sku', 'codigo', 'codigo_principal', 'producto', 'descripcion', 'nombre' );

        foreach ( $keys as $key ) {
            if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                return (string) $item[ $key ];
            }
        }

        foreach ( array( 'product_id', 'producto_id', 'id_producto', 'id' ) as $key ) {
            if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                return (string) $item[ $key ];
            }
        }

        return '#';
    }

    /**
     * Obtiene la cantidad disponible de stock desde la entrada de inventario.
     *
     * @param array $item Datos de inventario.
     *
     * @return float|null
     */
    protected function resolve_stock_quantity( array $item ) {
        $keys = array( 'stock', 'cantidad', 'available', 'quantity', 'qty', 'existencias', 'saldo' );

        foreach ( $keys as $key ) {
            if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                $value = $item[ $key ];

                if ( function_exists( 'wc_stock_amount' ) ) {
                    $value = wc_stock_amount( $value );
                } else {
                    $value = floatval( $value );
                }

                return max( 0, (float) $value );
            }
        }

        return null;
    }

    /**
     * Normaliza la respuesta de la API para trabajar siempre con una lista de bodegas.
     *
     * @param mixed $response Respuesta original de la API.
     *
     * @return array
     */
    protected function normalize_warehouses( $response ) {
        if ( empty( $response ) ) {
            return array();
        }

        if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
            $response = $response['results'];
        } elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $response = $response['data'];
        } elseif ( isset( $response['warehouses'] ) && is_array( $response['warehouses'] ) ) {
            $response = $response['warehouses'];
        }

        if ( ! is_array( $response ) ) {
            return array();
        }

        if ( $this->is_list( $response ) ) {
            return $response;
        }

        return array( $response );
    }

    /**
     * Verifica si el arreglo tiene índices numéricos consecutivos.
     *
     * @param array $data Arreglo a evaluar.
     *
     * @return bool
     */
    protected function is_list( array $data ) {
        if ( array() === $data ) {
            return true;
        }

        return array_keys( $data ) === range( 0, count( $data ) - 1 );
    }

    /**
     * Guarda la bitácora del último proceso de sincronización.
     *
     * @param string $status  Estado de la sincronización.
     * @param string $message Mensaje descriptivo.
     * @param array  $data    Información adicional.
     *
     * @return array
     */
    protected function store_log( $status, $message, array $data = array() ) {
        $log = array(
            'timestamp' => current_time( 'timestamp', true ),
            'status'    => $status,
            'message'   => $message,
            'data'      => $data,
        );

        update_option( self::LOG_OPTION, $log, false );

        return $log;
    }

    /**
     * Obtiene el cliente HTTP configurado.
     *
     * @return Contifico_WooCommerce_Api_Contifico_Client|null
     */
    protected function get_client() {
        if ( null === $this->client ) {
            $this->client = new Contifico_WooCommerce_Api_Contifico_Client( $this->get_logger() );
        }

        return $this->client;
    }

    /**
     * Obtiene una instancia del logger de WooCommerce.
     *
     * @return WC_Logger|null
     */
    protected function get_logger() {
        if ( null === $this->logger ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                $this->logger = wc_get_logger();
            } elseif ( class_exists( 'WC_Logger' ) ) {
                $this->logger = new WC_Logger();
            }
        }

        return $this->logger;
    }

    /**
     * Envía mensajes al logger si existe.
     *
     * @param string $level   Nivel del mensaje (info, warning, error).
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     *
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
        $logger = $this->get_logger();

        if ( ! $logger || ! method_exists( $logger, 'log' ) ) {
            return;
        }

        $defaults = array(
            'source' => Contifico_WooCommerce_Api_Contifico_Client::LOG_SOURCE,
        );

        $logger->log( $level, $message, array_merge( $defaults, $context ) );
    }

    /**
     * Limpia un valor de texto utilizando las funciones de WooCommerce si están disponibles.
     *
     * @param string $value Valor a limpiar.
     *
     * @return string
     */
    protected function clean( $value ) {
        if ( function_exists( 'wc_clean' ) ) {
            return wc_clean( $value );
        }

        return sanitize_text_field( $value );
    }

    /**
     * Devuelve un texto legible para el contexto actual de la sincronización.
     *
     * @param string $context Contexto registrado.
     *
     * @return string
     */
    protected function get_context_label( $context ) {
        switch ( $context ) {
            case 'scheduled':
                return esc_html__( 'programada', 'contifico-woocommerce' );
            case 'rest':
                return esc_html__( 'vía API', 'contifico-woocommerce' );
            case 'manual':
            default:
                return esc_html__( 'manual', 'contifico-woocommerce' );
        }
    }

    /**
     * Devuelve una descripción amigable del contexto de ejecución.
     *
     * @param string $context Contexto registrado en la bitácora.
     *
     * @return string
     */
    public static function get_context_description( $context ) {
        switch ( $context ) {
            case 'scheduled':
                return esc_html__( 'Programada', 'contifico-woocommerce' );
            case 'rest':
                return esc_html__( 'API (REST)', 'contifico-woocommerce' );
            case 'manual':
            default:
                return esc_html__( 'Manual', 'contifico-woocommerce' );
        }
    }

    /**
     * Almacena un aviso temporal para mostrarlo al usuario actual.
     *
     * @param string $status  Estado del aviso.
     * @param string $message Mensaje a mostrar.
     *
     * @return void
     */
    protected function store_notice_for_current_user( $status, $message ) {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            return;
        }

        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return;
        }

        set_transient(
            self::NOTICE_TRANSIENT_PREFIX . $user_id,
            array(
                'status'  => $status,
                'message' => $message,
            ),
            MINUTE_IN_SECONDS
        );
    }

    /**
     * Devuelve el nombre del meta key utilizado para el inventario.
     *
     * @return string
     */
    public static function get_meta_key() {
        return self::META_KEY;
    }

    /**
     * Devuelve la bitácora almacenada del último proceso de sincronización.
     *
     * @return array
     */
    public static function get_last_log() {
        $log = get_option( self::LOG_OPTION, array() );

        return is_array( $log ) ? $log : array();
    }

    /**
     * Obtiene y elimina el aviso temporal asociado a un usuario.
     *
     * @param int $user_id Identificador del usuario.
     *
     * @return array|null
     */
    public static function pop_notice_for_user( $user_id ) {
        $user_id = absint( $user_id );

        if ( ! $user_id ) {
            return null;
        }

        $key    = self::NOTICE_TRANSIENT_PREFIX . $user_id;
        $notice = get_transient( $key );

        if ( false !== $notice ) {
            delete_transient( $key );
        }

        return is_array( $notice ) ? $notice : null;
    }

    /**
     * Programa el evento de sincronización al activar el plugin.
     *
     * @return void
     */
    public static function activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $interval = apply_filters( 'contifico_woocommerce_inventory_sync_interval', 'hourly' );
            wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK );
        }
    }

    /**
     * Limpia el evento programado al desactivar el plugin.
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
