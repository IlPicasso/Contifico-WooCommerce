<?php
/**
 * Implementación mínima de WP_Error para ejecutar pruebas sin WordPress.
 */
class WP_Error {
    /**
     * Lista de errores agrupados por código.
     *
     * @var array
     */
    public $errors = array();

    /**
     * Datos adicionales asociados a cada error.
     *
     * @var array
     */
    public $error_data = array();

    /**
     * Constructor.
     *
     * @param string $code    Código del error.
     * @param string $message Mensaje descriptivo.
     * @param mixed  $data    Datos adicionales opcionales.
     */
    public function __construct( $code = '', $message = '', $data = '' ) {
        if ( empty( $code ) ) {
            return;
        }

        $this->errors[ $code ] = array();

        if ( '' !== $message ) {
            $this->errors[ $code ][] = $message;
        }

        if ( '' !== $data && null !== $data ) {
            $this->error_data[ $code ] = $data;
        }
    }

    /**
     * Añade un error adicional al objeto.
     *
     * @param string $code    Código del error.
     * @param string $message Mensaje a registrar.
     * @param mixed  $data    Datos adicionales opcionales.
     */
    public function add( $code, $message, $data = '' ) {
        if ( ! isset( $this->errors[ $code ] ) ) {
            $this->errors[ $code ] = array();
        }

        $this->errors[ $code ][] = $message;

        if ( '' !== $data && null !== $data ) {
            $this->error_data[ $code ] = $data;
        }
    }

    /**
     * Devuelve el primer código de error disponible.
     *
     * @return string
     */
    public function get_error_code() {
        $codes = array_keys( $this->errors );

        return isset( $codes[0] ) ? $codes[0] : '';
    }

    /**
     * Obtiene todos los mensajes registrados.
     *
     * @param string $code Código específico (opcional).
     *
     * @return array
     */
    public function get_error_messages( $code = '' ) {
        if ( '' !== $code ) {
            return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
        }

        $all = array();
        foreach ( $this->errors as $messages ) {
            $all = array_merge( $all, $messages );
        }

        return $all;
    }

    /**
     * Devuelve el primer mensaje disponible.
     *
     * @param string $code Código específico (opcional).
     *
     * @return string
     */
    public function get_error_message( $code = '' ) {
        $messages = $this->get_error_messages( $code );

        return isset( $messages[0] ) ? $messages[0] : '';
    }

    /**
     * Recupera los datos asociados a un error.
     *
     * @param string $code Código del error.
     *
     * @return mixed
     */
    public function get_error_data( $code = '' ) {
        if ( '' === $code ) {
            $code = $this->get_error_code();
        }

        return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
    }

    /**
     * Indica si existen errores registrados.
     *
     * @return bool
     */
    public function has_errors() {
        return ! empty( $this->errors );
    }
}
