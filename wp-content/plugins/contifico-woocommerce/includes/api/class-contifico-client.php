<?php
/**
 * Cliente HTTP para la API de Contifico.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encapsula las solicitudes a la API de Contifico y maneja la autenticación.
 */
class Contifico_WooCommerce_Api_Contifico_Client {

    /**
     * Nombre del option donde se guardan los ajustes.
     */
    const OPTION_NAME = 'contifico_woocommerce_settings';

    /**
     * Origen utilizado para los mensajes del logger.
     */
    const LOG_SOURCE = 'contifico-woocommerce';

    /**
     * Timeout por defecto para las peticiones.
     */
    const DEFAULT_TIMEOUT = 20;

    /**
     * Número de reintentos cuando la API responde con códigos recuperables.
     */
    const DEFAULT_RETRIES = 2;

    /**
     * URL base oficial de la API de Contifico.
     */
    const DEFAULT_BASE_URL = 'https://api.contifico.com/sistema/api/v1';

    /**
     * Instancia del logger utilizada para registrar eventos.
     *
     * @var WC_Logger|null
     */
    protected $logger;

    /**
     * Ajustes almacenados de la integración.
     *
     * @var array|null
     */
    protected $settings;

    /**
     * Constructor.
     *
     * @param WC_Logger|null $logger   Logger personalizado.
     * @param array|null     $settings Ajustes inyectados (principalmente para pruebas).
     */
    public function __construct( $logger = null, ?array $settings = null ) {
        $this->logger   = $logger;
        $this->settings = null !== $settings ? $this->prepare_settings( $settings ) : null;
    }

    /**
     * Obtiene el listado de productos desde Contifico.
     *
     * @param array $params Parámetros opcionales de búsqueda.
     *
     * @return array|WP_Error
     */
    public function get_items( array $params = array() ) {
        return $this->request(
            'GET',
            '/producto/',
            array(
                'query' => $params,
            )
        );
    }

    /**
     * Obtiene la lista de bodegas disponibles en Contifico.
     *
     * @param array $params Parámetros opcionales de búsqueda.
     *
     * @return array|WP_Error
     */
    public function get_warehouses( array $params = array() ) {
        return $this->request(
            'GET',
            '/bodega/',
            array(
                'query' => $params,
            )
        );
    }

    /**
     * Obtiene el inventario de una bodega específica.
     *
     * @param string $warehouse_id Identificador de la bodega.
     * @param array  $params       Parámetros adicionales para la consulta.
     *
     * @return array|WP_Error
     */
    public function get_inventory_by_warehouse( $warehouse_id, array $params = array() ) {
        if ( '' === $warehouse_id ) {
            return $this->create_wp_error(
                'contifico_missing_warehouse_id',
                $this->translate( 'Debes especificar una bodega válida para consultar el inventario.' )
            );
        }

        $warehouse_id = rawurlencode( (string) $warehouse_id );

        return $this->request(
            'GET',
            sprintf( '/inventario/stock/bodega/%s/', $warehouse_id ),
            array(
                'query' => $params,
            )
        );
    }

    /**
     * Registra una factura en Contifico.
     *
     * @param array $invoice Datos de la factura a crear.
     *
     * @return array|WP_Error
     */
    public function create_invoice( array $invoice ) {
        return $this->request(
            'POST',
            '/documento/',
            array(
                'body' => $invoice,
            )
        );
    }

    /**
     * Actualiza el stock de un producto en Contifico.
     *
     * @param string $item_id Identificador del producto en Contifico.
     * @param array  $payload Datos de inventario a sincronizar.
     *
     * @return array|WP_Error
     */
    public function update_stock( $item_id, array $payload ) {
        if ( empty( $item_id ) ) {
            return $this->create_wp_error(
                'contifico_missing_item_id',
                $this->translate( 'Se requiere un identificador de producto para actualizar el stock.' )
            );
        }

        $item_id  = (string) $item_id;
        $movement = $payload;

        if ( empty( $movement['tipo'] ) ) {
            $movement['tipo'] = 'AJU';
        }

        if ( empty( $movement['fecha'] ) ) {
            $movement['fecha'] = function_exists( 'gmdate' ) ? gmdate( 'd/m/Y' ) : date( 'd/m/Y' );
        }

        if ( isset( $movement['detalles'] ) ) {
            if ( ! is_array( $movement['detalles'] ) || empty( $movement['detalles'] ) ) {
                return $this->create_wp_error(
                    'contifico_invalid_stock_details',
                    $this->translate( 'Debes proporcionar al menos un detalle de producto para registrar el movimiento.' )
                );
            }

            $normalized_details = array();
            $has_item_detail    = false;

            foreach ( $movement['detalles'] as $detail ) {
                if ( ! is_array( $detail ) ) {
                    return $this->create_wp_error(
                        'contifico_invalid_stock_details',
                        $this->translate( 'Cada detalle de inventario debe ser un arreglo asociativo.' )
                    );
                }

                if ( empty( $detail['producto_id'] ) ) {
                    $detail['producto_id'] = $item_id;
                }

                if ( isset( $movement['cantidad'] ) && ! isset( $detail['cantidad'] ) ) {
                    $detail['cantidad'] = $movement['cantidad'];
                }

                if ( isset( $movement['precio'] ) && ! isset( $detail['precio'] ) ) {
                    $detail['precio'] = $movement['precio'];
                }

                if ( (string) $detail['producto_id'] === $item_id ) {
                    $has_item_detail = true;
                }

                $normalized_details[] = $detail;
            }

            if ( ! $has_item_detail ) {
                $fallback_detail = array(
                    'producto_id' => $item_id,
                );

                if ( isset( $movement['cantidad'] ) ) {
                    $fallback_detail['cantidad'] = $movement['cantidad'];
                }

                if ( isset( $movement['precio'] ) ) {
                    $fallback_detail['precio'] = $movement['precio'];
                }

                $normalized_details[] = $fallback_detail;
            }

            $movement['detalles'] = array_values( array_filter(
                $normalized_details,
                function ( $detail ) {
                    return is_array( $detail ) && ! empty( $detail['producto_id'] );
                }
            ) );
        } else {
            $detail = array(
                'producto_id' => $item_id,
            );

            if ( isset( $movement['cantidad'] ) ) {
                $detail['cantidad'] = $movement['cantidad'];
            }

            if ( isset( $movement['precio'] ) ) {
                $detail['precio'] = $movement['precio'];
            }

            $movement['detalles'] = array( $detail );
        }

        unset( $movement['cantidad'], $movement['precio'] );

        if ( empty( $movement['detalles'] ) ) {
            return $this->create_wp_error(
                'contifico_invalid_stock_details',
                $this->translate( 'No fue posible construir los detalles del movimiento de inventario.' )
            );
        }

        if ( empty( $movement['bodega_id'] ) ) {
            $settings = $this->get_settings();
            if ( ! empty( $settings['warehouse'] ) ) {
                $movement['bodega_id'] = $settings['warehouse'];
            }
        }

        return $this->request(
            'POST',
            '/movimiento-inventario/',
            array(
                'body' => $movement,
            )
        );
    }

    /**
     * Registra una transferencia de inventario entre bodegas.
     *
     * @param array $movement Datos del movimiento a crear.
     *
     * @return array|WP_Error
     */
    public function create_inventory_transfer( array $movement ) {
        $normalized = $this->prepare_inventory_transfer_payload( $movement );

        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        $this->log(
            'info',
            'Enviando transferencia de inventario a Contifico.',
            array(
                'source'      => $normalized['bodega_id'],
                'destination' => $normalized['bodega_destino_id'],
                'lines'       => count( $normalized['detalles'] ),
            )
        );

        $response = $this->request(
            'POST',
            '/movimiento-inventario/',
            array(
                'body' => $normalized,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $this->log(
            'info',
            'Transferencia de inventario registrada correctamente en Contifico.',
            array(
                'source'      => $normalized['bodega_id'],
                'destination' => $normalized['bodega_destino_id'],
                'lines'       => count( $normalized['detalles'] ),
            )
        );

        return $response;
    }

    /**
     * Normaliza el payload recibido para crear una transferencia de inventario.
     *
     * @param array $movement Movimiento recibido externamente.
     *
     * @return array|WP_Error
     */
    protected function prepare_inventory_transfer_payload( array $movement ) {
        if ( empty( $movement['detalles'] ) || ! is_array( $movement['detalles'] ) ) {
            return $this->create_wp_error(
                'contifico_invalid_transfer_details',
                $this->translate( 'Debes incluir al menos un producto para registrar la transferencia de inventario.' )
            );
        }

        $details = array();

        foreach ( $movement['detalles'] as $detail ) {
            if ( ! is_array( $detail ) || empty( $detail['producto_id'] ) ) {
                continue;
            }

            $quantity = isset( $detail['cantidad'] ) ? $detail['cantidad'] : null;

            if ( null === $quantity ) {
                continue;
            }

            if ( function_exists( 'wc_stock_amount' ) ) {
                $quantity = wc_stock_amount( $quantity );
            } else {
                $quantity = (float) $quantity;
            }

            if ( $quantity <= 0 ) {
                continue;
            }

            $entry = array(
                'producto_id' => (string) $detail['producto_id'],
                'cantidad'    => $quantity,
            );

            if ( isset( $detail['precio'] ) && '' !== $detail['precio'] ) {
                $entry['precio'] = $detail['precio'];
            }

            $details[] = $entry;
        }

        if ( empty( $details ) ) {
            return $this->create_wp_error(
                'contifico_invalid_transfer_details',
                $this->translate( 'Debes incluir al menos un producto para registrar la transferencia de inventario.' )
            );
        }

        if ( empty( $movement['bodega_id'] ) || empty( $movement['bodega_destino_id'] ) ) {
            return $this->create_wp_error(
                'contifico_missing_transfer_warehouse',
                $this->translate( 'Debes especificar la bodega de origen y destino para registrar la transferencia.' )
            );
        }

        $movement['detalles']          = array_values( $details );
        $movement['bodega_id']         = (string) $movement['bodega_id'];
        $movement['bodega_destino_id'] = (string) $movement['bodega_destino_id'];

        if ( empty( $movement['fecha'] ) ) {
            $movement['fecha'] = function_exists( 'gmdate' ) ? gmdate( 'd/m/Y' ) : date( 'd/m/Y' );
        }

        if ( empty( $movement['tipo'] ) ) {
            $movement['tipo'] = 'TRA';
        }

        if ( isset( $movement['descripcion'] ) ) {
            $movement['descripcion'] = (string) $movement['descripcion'];
        }

        return $movement;
    }

    /**
     * Realiza la solicitud HTTP principal contra la API de Contifico.
     *
     * @param string $method   Método HTTP (GET, POST, PUT, ...).
     * @param string $endpoint Ruta relativa del recurso.
     * @param array  $options  Opciones adicionales de la petición.
     *
     * @return array|WP_Error
     */
    protected function request( $method, $endpoint, array $options = array() ) {
        $options = array_merge(
            array(
                'body'    => null,
                'headers' => array(),
                'query'   => array(),
                'timeout' => self::DEFAULT_TIMEOUT,
                'retries' => self::DEFAULT_RETRIES,
            ),
            $options
        );

        $method = strtoupper( $method );

        $base_url = $this->get_base_url();
        if ( $this->is_error( $base_url ) ) {
            return $base_url;
        }

        $token = $this->get_auth_token();
        if ( $this->is_error( $token ) ) {
            return $token;
        }

        $url = $this->build_url( $base_url, $endpoint, $options['query'] );

        $headers = array_merge(
            $this->get_default_headers( $token ),
            $options['headers']
        );

        $request_args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => max( 1, (int) $options['timeout'] ),
        );

        if ( null !== $options['body'] ) {
            $prepared_body = $this->prepare_body( $options['body'] );
            if ( $this->is_error( $prepared_body ) ) {
                return $prepared_body;
            }

            $request_args['body'] = $prepared_body;

            if ( ! isset( $request_args['headers']['Content-Type'] ) ) {
                $request_args['headers']['Content-Type'] = 'application/json';
            }
        }

        $this->log(
            'debug',
            __( 'Enviando solicitud a Contifico.', 'contifico-woocommerce' ),
            array(
                'method' => $method,
                'url'    => $url,
                'query'  => $options['query'],
                'body'   => isset( $request_args['body'] ) ? $request_args['body'] : '',
            )
        );

        $attempt     = 0;
        $max_attempts = max( 1, (int) $options['retries'] + 1 );
        $last_error  = null;

        do {
            $attempt++;

            $response = wp_remote_request( $url, $request_args );

            if ( $this->is_error( $response ) ) {
                $last_error = $this->handle_transport_error( $response, $endpoint, $attempt );

                if ( $attempt >= $max_attempts ) {
                    return $last_error;
                }

                $this->wait_before_retry( $attempt );
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code( $response );

            if ( $status_code >= 200 && $status_code < 300 ) {
                $this->log(
                    'debug',
                    __( 'Respuesta recibida de Contifico.', 'contifico-woocommerce' ),
                    array(
                        'status_code' => $status_code,
                    )
                );
                return $this->handle_success_response( $response );
            }

            if ( $this->should_retry_status( $status_code ) && $attempt < $max_attempts ) {
                $this->log(
                    'warning',
                    sprintf( 'Reintentando solicitud debido a código HTTP %d.', $status_code ),
                    array(
                        'endpoint' => $endpoint,
                        'attempt'  => $attempt,
                    )
                );

                $this->wait_before_retry( $attempt );
                continue;
            }

            return $this->handle_http_error( $response, $status_code, $endpoint );
        } while ( $attempt < $max_attempts );

        return $last_error ? $last_error : $this->create_wp_error(
            'contifico_http_request_error',
            $this->translate( 'No se pudo comunicar con el servicio de Contifico.' )
        );
    }

    /**
     * Prepara el cuerpo de la petición asegurando que sea JSON válido.
     *
     * @param mixed $body Datos a codificar.
     *
     * @return string|WP_Error
     */
    protected function prepare_body( $body ) {
        if ( is_array( $body ) || is_object( $body ) ) {
            $encoded = $this->json_encode( $body );

            if ( false === $encoded ) {
                return $this->create_wp_error(
                    'contifico_json_encoding_error',
                    $this->translate( 'No fue posible preparar el cuerpo de la solicitud para Contifico.' )
                );
            }

            return $encoded;
        }

        if ( is_string( $body ) ) {
            return $body;
        }

        return $this->create_wp_error(
            'contifico_invalid_body',
            $this->translate( 'El formato del cuerpo de la solicitud no es válido.' )
        );
    }

    /**
     * Devuelve la URL base configurada para la API.
     *
     * @return string|WP_Error
     */
    protected function get_base_url() {
        $settings = $this->get_settings();
        $api_url  = isset( $settings['api_url'] ) ? trim( $settings['api_url'] ) : '';

        if ( '' === $api_url ) {
            $api_url = self::DEFAULT_BASE_URL;
        }

        return rtrim( $api_url, '/' );
    }

    /**
     * Obtiene el token de autenticación almacenado en los ajustes.
     *
     * @return string|WP_Error
     */
    protected function get_auth_token() {
        $settings = $this->get_settings();
        $api_key = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

        if ( '' === $api_key ) {
            return $this->create_wp_error(
                'contifico_missing_token',
                $this->translate( 'Configura el API Key de Contifico antes de realizar solicitudes.' )
            );
        }

        return $api_key;
    }

    /**
     * Construye la URL completa para la petición.
     *
     * @param string $base_url Base de la API.
     * @param string $endpoint Ruta del recurso.
     * @param array  $query    Parámetros de consulta.
     *
     * @return string
     */
    protected function build_url( $base_url, $endpoint, array $query = array() ) {
        $base_url = rtrim( $base_url, '/' );
        $endpoint = '/' . ltrim( $endpoint, '/' );
        $url      = $base_url . $endpoint;

        if ( ! empty( $query ) ) {
            $url .= '?' . http_build_query( $query, '', '&' );
        }

        return $url;
    }

    /**
     * Maneja la respuesta exitosa de la API.
     *
     * @param array $response Respuesta HTTP.
     *
     * @return array|WP_Error
     */
    protected function handle_success_response( $response ) {
        $body = wp_remote_retrieve_body( $response );

        if ( '' === trim( (string) $body ) ) {
            return array();
        }

        $decoded = json_decode( $body, true );

        if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
            $this->log(
                'error',
                'No se pudo decodificar la respuesta de Contifico.',
                array( 'body' => $body )
            );

            return $this->create_wp_error(
                'contifico_invalid_response',
                $this->translate( 'Contifico devolvió una respuesta inválida.' ),
                array( 'body' => $body )
            );
        }

        return $decoded;
    }

    /**
     * Genera un WP_Error a partir de una respuesta HTTP con error.
     *
     * @param array  $response    Respuesta HTTP completa.
     * @param int    $status_code Código de estado recibido.
     * @param string $endpoint    Endpoint solicitado.
     *
     * @return WP_Error
     */
    protected function handle_http_error( $response, $status_code, $endpoint ) {
        $body    = wp_remote_retrieve_body( $response );
        $message = $this->parse_error_message( $body );

        if ( '' === $message ) {
            $message = sprintf(
                $this->translate( 'La API de Contifico devolvió el código de estado %d.' ),
                $status_code
            );
        }

        $this->log(
            'error',
            'Error HTTP recibido desde Contifico.',
            array(
                'endpoint' => $endpoint,
                'status'   => $status_code,
                'body'     => $body,
            )
        );

        return $this->create_wp_error(
            'contifico_http_error',
            $message,
            array(
                'status' => $status_code,
                'body'   => $body,
            )
        );
    }

    /**
     * Maneja los errores de transporte devueltos por wp_remote_request.
     *
     * @param WP_Error $error    Error original.
     * @param string   $endpoint Endpoint solicitado.
     * @param int      $attempt  Número de intento realizado.
     *
     * @return WP_Error
     */
    protected function handle_transport_error( $error, $endpoint, $attempt ) {
        $message = $error->get_error_message();

        $this->log(
            'error',
            'Error de transporte al contactar con Contifico.',
            array(
                'endpoint' => $endpoint,
                'attempt'  => $attempt,
                'message'  => $message,
            )
        );

        return $this->create_wp_error(
            'contifico_http_request_error',
            $this->translate( 'No se pudo comunicar con el servicio de Contifico.' ),
            array(
                'previous' => $error,
            )
        );
    }

    /**
     * Determina si una respuesta HTTP amerita reintentar la solicitud.
     *
     * @param int $status_code Código de estado HTTP.
     *
     * @return bool
     */
    protected function should_retry_status( $status_code ) {
        $retryable = array( 408, 425, 429, 500, 502, 503, 504 );

        return in_array( (int) $status_code, $retryable, true );
    }

    /**
     * Espera un tiempo antes de repetir una solicitud.
     *
     * @param int $attempt Número de intento actual.
     *
     * @return void
     */
    protected function wait_before_retry( $attempt ) {
        if ( defined( 'CONTIFICO_WOOCOMMERCE_DISABLE_RETRY_DELAY' ) && CONTIFICO_WOOCOMMERCE_DISABLE_RETRY_DELAY ) {
            return;
        }

        $delay = min( 3, $attempt ) * 0.25; // Incremento leve para evitar bloqueos.

        if ( function_exists( 'wp_sleep' ) ) {
            wp_sleep( (int) ceil( $delay ) );
            return;
        }

        usleep( (int) ( $delay * 1000000 ) );
    }

    /**
     * Registra mensajes en el logger configurado.
     *
     * @param string $level   Nivel del mensaje.
     * @param string $message Contenido del mensaje.
     * @param array  $context Datos adicionales.
     *
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
        if ( ! $this->should_log_level( $level ) ) {
            return;
        }

        $normalized_context = array();

        foreach ( $context as $key => $value ) {
            $normalized_context[ $key ] = $this->stringify_for_log( $value );
        }

        $logger = $this->get_logger();

        if ( $logger ) {
            $logger->log(
                $level,
                $message,
                array_merge(
                    array( 'source' => self::LOG_SOURCE ),
                    $normalized_context
                )
            );
            return;
        }

        $context_output = '';

        if ( ! empty( $normalized_context ) ) {
            $encoded = $this->json_encode( $normalized_context );
            if ( false !== $encoded ) {
                $context_output = ' ' . $encoded;
            }
        }

        error_log( sprintf( '[%s] %s%s', strtoupper( $level ), $message, $context_output ) );
    }

    /**
     * Determina si la configuración permite registrar el evento.
     *
     * @param string $level Nivel solicitado.
     *
     * @return bool
     */
    protected function should_log_level( $level ) {
        $level            = strtolower( (string) $level );
        $critical_levels  = array( 'emergency', 'alert', 'critical', 'error' );

        if ( in_array( $level, $critical_levels, true ) ) {
            return true;
        }

        return $this->is_logging_enabled();
    }

    /**
     * Obtiene el logger disponible en WooCommerce.
     *
     * @return WC_Logger|null
     */
    protected function get_logger() {
        if ( null !== $this->logger ) {
            return $this->logger;
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }

        return $this->logger;
    }

    /**
     * Verifica si el registro detallado está activo en los ajustes.
     *
     * @return bool
     */
    protected function is_logging_enabled() {
        $settings = $this->get_settings();

        return isset( $settings['api_logging_enabled'] ) && 'yes' === $settings['api_logging_enabled'];
    }

    /**
     * Devuelve una representación segura para volcar en el log.
     *
     * @param mixed $value Valor original.
     *
     * @return string
     */
    protected function stringify_for_log( $value ) {
        if ( is_bool( $value ) ) {
            $value = $value ? 'true' : 'false';
        }

        if ( is_scalar( $value ) || null === $value ) {
            return $this->truncate_log_value( (string) $value );
        }

        if ( is_array( $value ) || is_object( $value ) ) {
            $encoded = $this->json_encode( $value );

            if ( false !== $encoded ) {
                return $this->truncate_log_value( $encoded );
            }

            if ( function_exists( 'print_r' ) ) {
                return $this->truncate_log_value( print_r( $value, true ) );
            }
        }

        return '';
    }

    /**
     * Corta un valor para evitar saturar el archivo de log.
     *
     * @param string $value Texto original.
     *
     * @return string
     */
    protected function truncate_log_value( $value ) {
        $max_length = (int) apply_filters( 'contifico_woocommerce_log_value_length', 2000 );

        if ( function_exists( 'mb_strlen' ) ) {
            if ( mb_strlen( $value ) > $max_length ) {
                return mb_substr( $value, 0, $max_length ) . '…';
            }

            return $value;
        }

        if ( strlen( $value ) > $max_length ) {
            return substr( $value, 0, $max_length ) . '…';
        }

        return $value;
    }

    /**
     * Determina si el valor entregado es un WP_Error.
     *
     * @param mixed $value Valor a evaluar.
     *
     * @return bool
     */
    protected function is_error( $value ) {
        return ( is_object( $value ) && $value instanceof WP_Error );
    }

    /**
     * Devuelve los ajustes guardados, en cache si ya se solicitaron anteriormente.
     *
     * @return array
     */
    protected function get_settings() {
        if ( null !== $this->settings ) {
            return $this->settings;
        }

        $stored = array();

        if ( function_exists( 'get_option' ) ) {
            $stored = get_option( self::OPTION_NAME, array() );
        }

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $this->settings = $this->prepare_settings( $stored );

        return $this->settings;
    }

    /**
     * Asegura que los ajustes contengan las claves esperadas.
     *
     * @param array $settings Ajustes originales.
     *
     * @return array
     */
    protected function prepare_settings( array $settings ) {
        $defaults = array(
            'api_url'             => '',
            'api_key'             => '',
            'api_secret'          => '',
            'warehouse'           => '',
            'api_logging_enabled' => 'no',
        );

        return array_merge( $defaults, $settings );
    }

    /**
     * Devuelve la ruta completa del archivo de log generado por WooCommerce.
     *
     * @return string
     */
    public static function get_log_file_path() {
        if ( ! class_exists( 'WC_Log_Handler_File' ) ) {
            return '';
        }

        return WC_Log_Handler_File::get_log_file_path( self::LOG_SOURCE );
    }

    /**
     * Crea un objeto WP_Error consistente.
     *
     * @param string $code    Código del error.
     * @param string $message Mensaje descriptivo.
     * @param array  $data    Datos adicionales.
     *
     * @return WP_Error
     */
    protected function create_wp_error( $code, $message, array $data = array() ) {
        return new WP_Error( $code, $message, $data );
    }

    /**
     * Obtiene los encabezados mínimos que deben incluirse en la petición.
     *
     * @param string $token Token de autenticación.
     *
     * @return array
     */
    protected function get_default_headers( $token ) {
        $headers = array(
            'Accept' => 'application/json',
        );

        if ( '' !== trim( (string) $token ) ) {
            $headers['Authorization'] = $token;
        }

        $headers['User-Agent'] = 'Contifico WooCommerce/' . ( defined( 'CONTIFICO_WOOCOMMERCE_VERSION' ) ? CONTIFICO_WOOCOMMERCE_VERSION : '1.0.0' );

        return $headers;
    }

    /**
     * Extrae un mensaje de error de una respuesta JSON de la API.
     *
     * @param string $body Cuerpo recibido.
     *
     * @return string
     */
    protected function parse_error_message( $body ) {
        $body = (string) $body;

        if ( '' === trim( $body ) ) {
            return '';
        }

        $decoded = json_decode( $body, true );

        if ( null === $decoded || JSON_ERROR_NONE !== json_last_error() ) {
            return '';
        }

        if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
            return $decoded['message'];
        }

        if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
            return $decoded['error'];
        }

        if ( isset( $decoded['errors'] ) ) {
            if ( is_array( $decoded['errors'] ) ) {
                $messages = array();
                foreach ( $decoded['errors'] as $error ) {
                    if ( is_string( $error ) ) {
                        $messages[] = $error;
                    } elseif ( is_array( $error ) ) {
                        $messages = array_merge( $messages, array_filter( $error, 'is_string' ) );
                    }
                }

                return implode( ' ', array_filter( $messages ) );
            }

            if ( is_string( $decoded['errors'] ) ) {
                return $decoded['errors'];
            }
        }

        return '';
    }

    /**
     * Codifica datos a JSON utilizando las utilidades de WordPress si están disponibles.
     *
     * @param mixed $data Datos a codificar.
     *
     * @return string|false
     */
    protected function json_encode( $data ) {
        if ( function_exists( 'wp_json_encode' ) ) {
            return wp_json_encode( $data );
        }

        return json_encode( $data );
    }

    /**
     * Traduce un texto si la función de internacionalización está disponible.
     *
     * @param string $text Texto original.
     *
     * @return string
     */
    protected function translate( $text ) {
        if ( function_exists( '__' ) ) {
            return __( $text, 'contifico-woocommerce' );
        }

        return $text;
    }
}
