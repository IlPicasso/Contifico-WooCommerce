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
     * Hook registrado en Action Scheduler para procesar cada lote.
     */
    const BATCH_ACTION = 'contifico_woocommerce_inventory_sync_batch';

    /**
     * Option que almacena el estado de la sincronización por lotes.
     */
    const BATCH_STATE_OPTION = 'contifico_woocommerce_inventory_batch_state';

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
     * Prefijo de los transients que almacenan el stock de una bodega durante el proceso.
     */
    const STOCK_CACHE_TRANSIENT_PREFIX = 'contifico_inventory_stock_cache_';

    /**
     * Tamaño por defecto de cada lote.
     */
    const DEFAULT_BATCH_SIZE = 100;

    /**
     * Grupo utilizado para las acciones programadas del plugin.
     */
    const ACTION_SCHEDULER_GROUP = 'contifico-woocommerce';

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
        add_action( self::BATCH_ACTION, array( $this, 'handle_batch_action' ), 10, 1 );
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
     * Acción registrada en Action Scheduler para procesar cada lote.
     *
     * @param array|int $payload Datos asociados al lote encolado.
     *
     * @return void
     */
    public function handle_batch_action( $payload = array() ) {
        $step = 1;

        if ( is_array( $payload ) && isset( $payload['step'] ) ) {
            $step = absint( $payload['step'] );
        } elseif ( is_numeric( $payload ) ) {
            $step = absint( $payload );
        }

        if ( $step < 1 ) {
            $step = 1;
        }

        $this->process_batch_step( $step );
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
        if ( $this->can_use_batch_processing() ) {
            return $this->start_batch_sync( $context );
        }

        return $this->run_immediate_inventory_sync( $context );
    }

    /**
     * Ejecuta la sincronización de inventario de forma inmediata sin lotes.
     *
     * @param string $context Contexto de ejecución.
     *
     * @return array|WP_Error
     */
    protected function run_immediate_inventory_sync( $context ) {
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
     * Inicia la sincronización de inventario en modo por lotes utilizando Action Scheduler.
     *
     * @param string $context Contexto desde el cual se ejecuta la sincronización.
     *
     * @return array|WP_Error
     */
    public function start_batch_sync( $context = 'manual' ) {
        if ( $this->is_batch_running() ) {
            $error = new WP_Error(
                'contifico_inventory_sync_running',
                esc_html__( 'Ya existe una sincronización de inventario en ejecución.', 'contifico-woocommerce' )
            );

            $this->log( 'warning', $error->get_error_message(), array( 'context' => $context ) );

            return $error;
        }

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

        if ( empty( $warehouses ) ) {
            return $this->run_immediate_inventory_sync( $context );
        }

        $prepared_warehouses = array();

        foreach ( $warehouses as $warehouse ) {
            $warehouse_id = $this->get_warehouse_id( $warehouse );

            if ( '' === $warehouse_id ) {
                continue;
            }

            $prepared_warehouses[ $warehouse_id ] = array(
                'name'             => $this->get_warehouse_name( $warehouse, $warehouse_id ),
                'stock_cache_key'  => '',
                'stock_initialized' => false,
            );
        }

        if ( empty( $prepared_warehouses ) ) {
            return $this->run_immediate_inventory_sync( $context );
        }

        $batch_size = $this->get_batch_size();

        $state = array(
            'context'            => $context,
            'batch_size'         => $batch_size,
            'current_step'       => 1,
            'started_at'         => current_time( 'timestamp', true ),
            'warehouses'         => $prepared_warehouses,
            'cleanup_candidates' => array(),
            'processed_products' => array(),
            'items_processed'    => 0,
            'products_cleaned'   => 0,
            'errors'             => array(),
        );

        $existing_inventory = $this->get_products_with_inventory_meta();

        if ( ! empty( $existing_inventory ) ) {
            $state['cleanup_candidates'] = array_fill_keys( $existing_inventory, true );
        }

        $this->save_batch_state( $state );
        $this->enqueue_batch_step( 1 );

        $message = esc_html__( 'Se inició la sincronización de inventario en segundo plano.', 'contifico-woocommerce' );

        $this->log( 'info', $message, array( 'context' => $context ) );

        return array(
            'message' => $message,
            'summary' => array(
                'status'     => 'queued',
                'warehouses' => count( $prepared_warehouses ),
                'batch_size' => $batch_size,
                'context'    => $context,
            ),
        );
    }

    /**
     * Ejecuta el procesamiento de un lote específico dentro de la sincronización.
     *
     * @param int $step Número del lote a procesar.
     *
     * @return void
     */
    protected function process_batch_step( $step ) {
        $state = $this->get_batch_state();

        if ( empty( $state ) ) {
            return;
        }

        $context    = isset( $state['context'] ) ? $state['context'] : 'manual';
        $batch_size = isset( $state['batch_size'] ) ? absint( $state['batch_size'] ) : $this->get_batch_size();
        $batch_size = $batch_size > 0 ? $batch_size : $this->get_batch_size();

        $client = $this->get_client();

        if ( ! $client ) {
            $error = new WP_Error(
                'contifico_missing_client',
                esc_html__( 'No fue posible inicializar el cliente de Contifico.', 'contifico-woocommerce' )
            );

            $this->log( 'error', $error->get_error_message(), array( 'context' => $context ) );
            $this->store_log( 'error', $error->get_error_message(), array( 'context' => $context ) );
            $this->clear_batch_state( $state );

            return;
        }

        $response = $client->get_items(
            array(
                'result_page' => $step,
                'result_size' => $batch_size,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', $response->get_error_message(), array( 'context' => $context, 'step' => $step ) );
            $this->store_log(
                'error',
                $response->get_error_message(),
                array(
                    'context'    => $context,
                    'step'       => $step,
                    'error_code' => $response->get_error_code(),
                )
            );
            $this->clear_batch_state( $state );

            return;
        }

        $products = $this->normalize_products_batch( $response );

        if ( empty( $products ) ) {
            $this->finalize_batch_processing( $state );
            return;
        }

        $timestamp = current_time( 'mysql', true );

        $warehouses_payload = $this->prepare_warehouses_payload_for_batch( $products, $state, $client );

        $batch_summary = $this->process_inventory_response(
            $warehouses_payload,
            array(
                'clean_stale'        => false,
                'existing_inventory' => array_keys( isset( $state['cleanup_candidates'] ) ? $state['cleanup_candidates'] : array() ),
                'timestamp'          => $timestamp,
            )
        );

        if ( isset( $batch_summary['items_processed'] ) ) {
            $state['items_processed'] = isset( $state['items_processed'] )
                ? (int) $state['items_processed'] + (int) $batch_summary['items_processed']
                : (int) $batch_summary['items_processed'];
        }

        if ( isset( $batch_summary['processed_product_ids'] ) && is_array( $batch_summary['processed_product_ids'] ) ) {
            if ( ! isset( $state['processed_products'] ) || ! is_array( $state['processed_products'] ) ) {
                $state['processed_products'] = array();
            }

            foreach ( $batch_summary['processed_product_ids'] as $product_id ) {
                $product_id = absint( $product_id );

                if ( ! $product_id ) {
                    continue;
                }

                $state['processed_products'][ $product_id ] = true;

                if ( isset( $state['cleanup_candidates'][ $product_id ] ) ) {
                    unset( $state['cleanup_candidates'][ $product_id ] );
                }
            }
        }

        if ( ! empty( $batch_summary['errors'] ) ) {
            $state['errors'] = isset( $state['errors'] ) && is_array( $state['errors'] )
                ? array_merge( $state['errors'], $batch_summary['errors'] )
                : $batch_summary['errors'];
        }

        $state['current_step'] = $step + 1;

        $this->save_batch_state( $state );
        $this->enqueue_batch_step( $step + 1 );
    }

    /**
     * Construye la carga útil de bodegas para el lote actual.
     *
     * @param array                                      $products Productos normalizados devueltos por la API.
     * @param array                                      $state    Estado actual de la sincronización.
     * @param Contifico_WooCommerce_Api_Contifico_Client $client   Cliente HTTP de Contifico.
     *
     * @return array
     */
    protected function prepare_warehouses_payload_for_batch( array $products, array &$state, Contifico_WooCommerce_Api_Contifico_Client $client ) {
        $payload = array();

        if ( empty( $state['warehouses'] ) || ! is_array( $state['warehouses'] ) ) {
            return $payload;
        }

        foreach ( $state['warehouses'] as $warehouse_id => &$warehouse_state ) {
            $stock_map = $this->get_warehouse_stock_map( $client, $warehouse_id, $warehouse_state );

            if ( is_wp_error( $stock_map ) ) {
                $error_message = $stock_map->get_error_message();

                if ( ! isset( $state['errors'] ) || ! is_array( $state['errors'] ) ) {
                    $state['errors'] = array();
                }

                $state['errors'][] = $error_message;
                $stock_map        = array();
            }

            $items = array();

            foreach ( $products as $product ) {
                $sku          = isset( $product['sku'] ) ? $product['sku'] : '';
                $contifico_id = isset( $product['contifico_id'] ) ? (string) $product['contifico_id'] : '';
                $name         = isset( $product['name'] ) ? $product['name'] : '';

                $quantity = 0;

                if ( '' !== $contifico_id && isset( $stock_map[ $contifico_id ] ) ) {
                    $quantity = $stock_map[ $contifico_id ];
                }

                $items[] = array(
                    'sku'              => $sku,
                    'codigo'           => $sku,
                    'codigo_principal' => $sku,
                    'nombre'           => $name,
                    'contifico_id'     => $contifico_id,
                    'stock'            => $quantity,
                );
            }

            $payload[] = array(
                'id'     => $warehouse_id,
                'nombre' => $warehouse_state['name'],
                'items'  => $items,
            );
        }

        return $payload;
    }

    /**
     * Obtiene el mapa de stock para una bodega, usando caché temporal cuando es posible.
     *
     * @param Contifico_WooCommerce_Api_Contifico_Client $client          Cliente HTTP.
     * @param string                                     $warehouse_id    Identificador de la bodega.
     * @param array                                      $warehouse_state Estado almacenado de la bodega.
     *
     * @return array|WP_Error
     */
    protected function get_warehouse_stock_map( Contifico_WooCommerce_Api_Contifico_Client $client, $warehouse_id, array &$warehouse_state ) {
        $cache_key = isset( $warehouse_state['stock_cache_key'] ) ? $warehouse_state['stock_cache_key'] : '';

        if ( '' !== $cache_key ) {
            $cached = get_transient( $cache_key );

            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $response = $client->get_inventory_by_warehouse( $warehouse_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $stock_map = $this->normalize_stock_response( $response );

        $this->store_stock_cache_for_warehouse( $warehouse_id, $stock_map, $warehouse_state );

        return $stock_map;
    }

    /**
     * Guarda en un transient el stock asociado a una bodega.
     *
     * @param string $warehouse_id    Identificador de la bodega.
     * @param array  $stock_map       Mapa de existencias por producto.
     * @param array  $warehouse_state Estado actual de la bodega.
     *
     * @return void
     */
    protected function store_stock_cache_for_warehouse( $warehouse_id, array $stock_map, array &$warehouse_state ) {
        $ttl = apply_filters( 'contifico_woocommerce_inventory_stock_cache_ttl', HOUR_IN_SECONDS );

        if ( isset( $warehouse_state['stock_cache_key'] ) && '' !== $warehouse_state['stock_cache_key'] ) {
            set_transient( $warehouse_state['stock_cache_key'], $stock_map, $ttl );
        } else {
            $key = self::STOCK_CACHE_TRANSIENT_PREFIX . md5( $warehouse_id . '|' . microtime() . '|' . wp_rand() );
            set_transient( $key, $stock_map, $ttl );
            $warehouse_state['stock_cache_key'] = $key;
        }

        $warehouse_state['stock_initialized'] = true;
    }

    /**
     * Normaliza la respuesta de productos para trabajar siempre con una lista uniforme.
     *
     * @param mixed $response Respuesta de la API de Contifico.
     *
     * @return array
     */
    protected function normalize_products_batch( $response ) {
        if ( empty( $response ) ) {
            return array();
        }

        if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
            $response = $response['results'];
        } elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $response = $response['data'];
        }

        if ( ! is_array( $response ) ) {
            return array();
        }

        if ( ! $this->is_list( $response ) ) {
            $response = array( $response );
        }

        $products = array();

        foreach ( $response as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $contifico_id = $this->find_value_in_item( $item, array( 'id', 'producto_id', 'product_id', 'id_producto' ) );
            $sku          = $this->find_value_in_item( $item, array( 'sku', 'codigo', 'codigo_principal', 'codigo_auxiliar' ) );

            if ( '' === $contifico_id || '' === $sku ) {
                continue;
            }

            $products[] = array(
                'contifico_id' => (string) $contifico_id,
                'sku'          => (string) $sku,
                'name'         => $this->find_value_in_item( $item, array( 'nombre', 'name', 'descripcion', 'description' ) ),
                'raw'          => $item,
            );
        }

        return $products;
    }

    /**
     * Busca el primer valor válido dentro de un arreglo utilizando varias claves posibles.
     *
     * @param array $item Arreglo de datos.
     * @param array $keys Claves a evaluar.
     *
     * @return string
     */
    protected function find_value_in_item( array $item, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
                return (string) $item[ $key ];
            }
        }

        return '';
    }

    /**
     * Convierte la respuesta de stock de Contifico en un mapa por ID de producto.
     *
     * @param mixed $response Datos crudos de la API.
     *
     * @return array
     */
    protected function normalize_stock_response( $response ) {
        if ( empty( $response ) ) {
            return array();
        }

        if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
            $response = $response['results'];
        } elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $response = $response['data'];
        }

        if ( ! is_array( $response ) ) {
            return array();
        }

        if ( ! $this->is_list( $response ) ) {
            $response = array( $response );
        }

        $stock_map = array();

        foreach ( $response as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $product_id = $this->find_value_in_item( $row, array( 'producto_id', 'product_id', 'id_producto', 'id' ) );

            if ( '' === $product_id ) {
                continue;
            }

            $quantity = $this->find_value_in_item( $row, array( 'cantidad_stock', 'cantidad', 'stock', 'quantity' ) );

            if ( '' === $quantity ) {
                $quantity = 0;
            }

            if ( function_exists( 'wc_stock_amount' ) ) {
                $quantity = wc_stock_amount( $quantity );
            } else {
                $quantity = (float) $quantity;
            }

            $stock_map[ (string) $product_id ] = (float) $quantity;
        }

        return $stock_map;
    }

    /**
     * Finaliza la sincronización por lotes limpiando los productos pendientes y registrando la bitácora.
     *
     * @param array $state Estado acumulado de la sincronización.
     *
     * @return void
     */
    protected function finalize_batch_processing( array $state ) {
        $context = isset( $state['context'] ) ? $state['context'] : 'manual';

        $cleanup_candidates = array_keys( isset( $state['cleanup_candidates'] ) ? $state['cleanup_candidates'] : array() );
        $products_cleaned   = isset( $state['products_cleaned'] ) ? (int) $state['products_cleaned'] : 0;

        if ( ! empty( $cleanup_candidates ) ) {
            $products_cleaned += $this->cleanup_stale_inventory( array_map( 'absint', $cleanup_candidates ), array() );
        }

        $processed_products = isset( $state['processed_products'] ) && is_array( $state['processed_products'] )
            ? count( $state['processed_products'] )
            : 0;

        $summary = array(
            'warehouses'       => isset( $state['warehouses'] ) && is_array( $state['warehouses'] ) ? count( $state['warehouses'] ) : 0,
            'products_updated' => $processed_products,
            'items_processed'  => isset( $state['items_processed'] ) ? (int) $state['items_processed'] : 0,
            'products_cleaned' => $products_cleaned,
            'errors'           => isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array(),
            'mode'             => 'batch',
        );

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
            (int) $summary['products_updated']
        );

        $this->store_log(
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

        $state['products_cleaned'] = $products_cleaned;
        $state['finished']         = true;

        $this->clear_batch_state( $state );
    }

    /**
     * Procesa la información devuelta por la API y actualiza el stock de productos.
     *
     * @param array $warehouses Listado de bodegas.
     *
     * @return array
     */
    protected function process_inventory_response( array $warehouses, array $args = array() ) {
        $args = wp_parse_args(
            $args,
            array(
                'existing_inventory' => null,
                'clean_stale'        => true,
                'timestamp'          => null,
            )
        );

        $timestamp = $args['timestamp'];
        if ( null === $timestamp ) {
            $timestamp = current_time( 'mysql', true );
        }

        $existing_inventory = $args['existing_inventory'];
        if ( null === $existing_inventory ) {
            $existing_inventory = $this->get_products_with_inventory_meta();
        } else {
            $existing_inventory = array_map( 'absint', (array) $existing_inventory );
            $existing_inventory = array_values( array_filter( array_unique( $existing_inventory ) ) );
        }

        $built_inventory = $this->build_inventory_map( $warehouses, $timestamp );

        $products_cleaned = 0;
        if ( $args['clean_stale'] ) {
            $products_cleaned = $this->cleanup_stale_inventory(
                $existing_inventory,
                array_keys( $built_inventory['grouped_inventory'] )
            );
        }

        $this->persist_grouped_inventory( $built_inventory['grouped_inventory'] );

        return array(
            'warehouses'             => $built_inventory['warehouses_count'],
            'products_updated'       => count( $built_inventory['updated_products'] ),
            'items_processed'        => $built_inventory['items_processed'],
            'products_cleaned'       => $products_cleaned,
            'errors'                 => $built_inventory['errors'],
            'processed_product_ids'  => array_keys( $built_inventory['updated_products'] ),
        );
    }

    /**
     * Construye un mapa de inventario agrupado por producto y bodega.
     *
     * @param array  $warehouses Datos de bodegas recibidos desde la API.
     * @param string $timestamp  Marca de tiempo que se almacenará en los metadatos.
     *
     * @return array
     */
    protected function build_inventory_map( array $warehouses, $timestamp ) {
        $grouped_inventory = array();
        $updated_products  = array();
        $errors            = array();
        $warehouses_count  = 0;
        $items_processed   = 0;

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
                    'updated_at_gmt' => $timestamp,
                );

                $updated_products[ $product_id ] = true;
            }
        }

        return array(
            'grouped_inventory' => $grouped_inventory,
            'updated_products'  => $updated_products,
            'errors'            => $errors,
            'warehouses_count'  => $warehouses_count,
            'items_processed'   => $items_processed,
        );
    }

    /**
     * Elimina los metadatos de inventario que no aparecen en la respuesta procesada.
     *
     * @param array $existing_inventory IDs de productos que actualmente tienen metadata.
     * @param array $products_in_response IDs encontrados en la respuesta actual.
     *
     * @return int Número de productos limpiados.
     */
    protected function cleanup_stale_inventory( array $existing_inventory, array $products_in_response ) {
        $products_in_response = array_map( 'absint', $products_in_response );
        $products_in_response = array_values( array_filter( array_unique( $products_in_response ) ) );

        $stale_products   = array_diff( $existing_inventory, $products_in_response );
        $products_cleaned = 0;

        if ( empty( $stale_products ) ) {
            return 0;
        }

        foreach ( $stale_products as $product_id ) {
            $product_id = absint( $product_id );

            if ( ! $product_id ) {
                continue;
            }

            delete_post_meta( $product_id, self::META_KEY );
            $products_cleaned++;
        }

        return $products_cleaned;
    }

    /**
     * Guarda la información de inventario agrupada por producto.
     *
     * @param array $grouped_inventory Datos preparados por {@see build_inventory_map}.
     *
     * @return void
     */
    protected function persist_grouped_inventory( array $grouped_inventory ) {

        foreach ( $grouped_inventory as $product_id => $warehouses_stock ) {
            if ( empty( $warehouses_stock ) ) {
                delete_post_meta( $product_id, self::META_KEY );
                continue;
            }

            update_post_meta( $product_id, self::META_KEY, $warehouses_stock );
        }
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

        foreach ( array( 'product_id', 'producto_id', 'id_producto', 'id', 'contifico_id' ) as $key ) {
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
     * Obtiene el estado actual de la sincronización por lotes.
     *
     * @return array
     */
    protected function get_batch_state() {
        $state = get_option( self::BATCH_STATE_OPTION, array() );

        return is_array( $state ) ? $state : array();
    }

    /**
     * Guarda el estado de la sincronización por lotes.
     *
     * @param array $state Datos del estado.
     *
     * @return void
     */
    protected function save_batch_state( array $state ) {
        update_option( self::BATCH_STATE_OPTION, $state, false );
    }

    /**
     * Elimina el estado almacenado y limpia los transients asociados.
     *
     * @param array|null $state Estado a limpiar. Si es null se obtiene automáticamente.
     *
     * @return void
     */
    protected function clear_batch_state( ?array $state = null ) {
        if ( null === $state ) {
            $state = $this->get_batch_state();
        }

        if ( isset( $state['warehouses'] ) && is_array( $state['warehouses'] ) ) {
            foreach ( $state['warehouses'] as $warehouse ) {
                if ( isset( $warehouse['stock_cache_key'] ) && '' !== $warehouse['stock_cache_key'] ) {
                    delete_transient( $warehouse['stock_cache_key'] );
                }
            }
        }

        delete_option( self::BATCH_STATE_OPTION );
    }

    /**
     * Indica si existe un proceso de sincronización por lotes en curso.
     *
     * @return bool
     */
    protected function is_batch_running() {
        $state = $this->get_batch_state();

        return ! empty( $state ) && empty( $state['finished'] );
    }

    /**
     * Encola la siguiente acción de procesamiento en Action Scheduler.
     *
     * @param int $step Paso a ejecutar.
     *
     * @return void
     */
    protected function enqueue_batch_step( $step ) {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return;
        }

        $step = max( 1, absint( $step ) );

        as_enqueue_async_action(
            self::BATCH_ACTION,
            array(
                array(
                    'step' => $step,
                ),
            ),
            self::ACTION_SCHEDULER_GROUP
        );
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
     * Determina el tamaño del lote a utilizar durante la sincronización.
     *
     * @return int
     */
    protected function get_batch_size() {
        $size = apply_filters( 'contifico_woocommerce_inventory_batch_size', self::DEFAULT_BATCH_SIZE );
        $size = absint( $size );

        if ( $size <= 0 ) {
            $size = self::DEFAULT_BATCH_SIZE;
        }

        return $size;
    }

    /**
     * Verifica si el entorno actual permite utilizar Action Scheduler.
     *
     * @return bool
     */
    protected function can_use_batch_processing() {
        return function_exists( 'as_enqueue_async_action' );
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
