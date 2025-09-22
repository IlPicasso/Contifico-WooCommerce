<?php
/**
 * Autoloader del plugin Contifico WooCommerce.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase encargada de registrar el autoload de las clases del plugin.
 */
class Contifico_WooCommerce_Autoloader {

    /**
     * Instancia única del autoloader.
     *
     * @var Contifico_WooCommerce_Autoloader
     */
    protected static $instance;

    /**
     * Prefijo de las clases del plugin.
     *
     * @var string
     */
    protected $prefix = 'Contifico_WooCommerce_';

    /**
     * Ruta base del plugin.
     *
     * @var string
     */
    protected $base_dir;

    /**
     * Constructor privado para evitar instanciación externa.
     */
    private function __construct() {
        $this->base_dir = trailingslashit( dirname( dirname( __FILE__ ) ) );
    }

    /**
     * Registra el autoloader en SPL.
     *
     * @return void
     */
    public static function register() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        spl_autoload_register( array( self::$instance, 'autoload' ) );
    }

    /**
     * Carga el archivo que contiene la clase solicitada.
     *
     * @param string $class Nombre completo de la clase solicitada.
     *
     * @return void
     */
    public function autoload( $class ) {
        if ( 0 !== strpos( $class, $this->prefix ) ) {
            return;
        }

        $relative_class = strtolower( str_replace( $this->prefix, '', $class ) );
        $parts          = explode( '_', $relative_class );
        $directory      = 'includes';

        if ( isset( $parts[0] ) ) {
            if ( 'admin' === $parts[0] ) {
                $directory = 'admin';
                $parts     = array_slice( $parts, 1 );
                $parts     = ! empty( $parts ) ? $parts : array( 'admin' );
            } elseif ( 'public' === $parts[0] ) {
                $directory = 'public';
                $parts     = array_slice( $parts, 1 );
                $parts     = ! empty( $parts ) ? $parts : array( 'public' );
            }
        }

        $filename = 'class-' . implode( '-', $parts ) . '.php';
        $file     = $this->base_dir . $directory . '/' . $filename;

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
