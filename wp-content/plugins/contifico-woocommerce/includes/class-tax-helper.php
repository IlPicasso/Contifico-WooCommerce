<?php
/**
 * Utilidades relacionadas con la información tributaria requerida por Contifico.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Proporciona helpers comunes para manipular y validar datos fiscales.
 */
class Contifico_WooCommerce_Tax_Helper {

    /**
     * Devuelve el listado de tipos de identificación soportados.
     *
     * @return array
     */
    public static function get_tax_types() {
        return array(
            'cedula'    => __( 'Cédula', 'contifico-woocommerce' ),
            'ruc'       => __( 'RUC', 'contifico-woocommerce' ),
            'pasaporte' => __( 'Pasaporte', 'contifico-woocommerce' ),
            'exterior'  => __( 'Identificación del exterior', 'contifico-woocommerce' ),
        );
    }

    /**
     * Devuelve el listado de tipos de contribuyente.
     *
     * @return array
     */
    public static function get_taxpayer_types() {
        return array(
            'N' => __( 'Persona natural', 'contifico-woocommerce' ),
            'J' => __( 'Persona jurídica', 'contifico-woocommerce' ),
        );
    }

    /**
     * Indica si el valor representa una solicitud para emitir a nombre de empresa.
     *
     * @param mixed $value Valor a evaluar.
     *
     * @return bool
     */
    public static function is_company_subject( $value ) {
        return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
    }

    /**
     * Normaliza el valor recibido desde un checkbox.
     *
     * @param mixed $value Valor crudo del formulario.
     *
     * @return string
     */
    public static function sanitize_subject_value( $value ) {
        return self::is_company_subject( $value ) ? 'yes' : '';
    }

    /**
     * Verifica que el identificador tributario sea válido para el tipo seleccionado.
     *
     * @param string $type  Tipo de identificación.
     * @param string $value Número a validar.
     *
     * @return true|WP_Error
     */
    public static function validate_tax_id( $type, $value ) {
        $type  = strtolower( (string) $type );
        $value = self::clean_identifier( $value );

        if ( '' === $value ) {
            return new WP_Error(
                'contifico_invalid_tax_id',
                __( 'Debes proporcionar un número de identificación para emitir el documento electrónico.', 'contifico-woocommerce' )
            );
        }

        if ( 'cedula' === $type ) {
            if ( ! preg_match( '/^\d{10}$/', $value ) ) {
                return new WP_Error(
                    'contifico_invalid_cedula',
                    __( 'La cédula debe contener exactamente 10 dígitos.', 'contifico-woocommerce' )
                );
            }

            return true;
        }

        if ( 'ruc' === $type ) {
            if ( ! preg_match( '/^\d{13}$/', $value ) ) {
                return new WP_Error(
                    'contifico_invalid_ruc',
                    __( 'El RUC debe contener 13 dígitos.', 'contifico-woocommerce' )
                );
            }

            return true;
        }

        $allowed = array_keys( self::get_tax_types() );
        if ( ! in_array( $type, $allowed, true ) ) {
            return new WP_Error(
                'contifico_invalid_tax_type',
                __( 'Selecciona un tipo de identificación válido.', 'contifico-woocommerce' )
            );
        }

        // Para pasaporte o exterior no se aplica validación estricta.
        return true;
    }

    /**
     * Devuelve la definición de campos a utilizar en formularios de checkout o cuenta.
     *
     * @param bool $required Si los campos deberían ser obligatorios.
     * @param int  $user_id  Identificador de usuario para precargar valores.
     *
     * @return array
     */
    public static function get_account_fields( $required = true, $user_id = 0 ) {
        $customer_id = $user_id ? absint( $user_id ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );

        $taxpayer_type = 'N';
        $tax_subject   = '';
        $tax_type      = 'cedula';
        $tax_id        = '';

        if ( $customer_id > 0 ) {
            $taxpayer_type = get_user_meta( $customer_id, 'taxpayer_type', true );
            $tax_subject   = get_user_meta( $customer_id, 'tax_subject', true );
            $tax_type      = get_user_meta( $customer_id, 'tax_type', true );
            $tax_id        = get_user_meta( $customer_id, 'tax_id', true );
        }

        $fields = array(
            'taxpayer_type' => array(
                'type'     => 'select',
                'label'    => __( 'Tipo de contribuyente', 'contifico-woocommerce' ),
                'options'  => self::get_taxpayer_types(),
                'required' => (bool) $required,
                'priority' => 210,
                'class'    => array( 'form-row-wide' ),
                'value'    => $taxpayer_type ? $taxpayer_type : 'N',
            ),
            'tax_subject' => array(
                'type'        => 'checkbox',
                'label'       => __( '¿Emitir el documento a nombre de la empresa?', 'contifico-woocommerce' ),
                'required'    => false,
                'priority'    => 220,
                'class'       => array( 'form-row-wide' ),
                'value'       => self::sanitize_subject_value( $tax_subject ),
            ),
            'tax_type' => array(
                'type'     => 'select',
                'label'    => __( 'Tipo de identificación', 'contifico-woocommerce' ),
                'options'  => self::get_tax_types(),
                'required' => (bool) $required,
                'priority' => 230,
                'class'    => array( 'form-row-first' ),
                'value'    => $tax_type ? $tax_type : 'cedula',
            ),
            'tax_id' => array(
                'type'        => 'text',
                'label'       => __( 'Número de identificación', 'contifico-woocommerce' ),
                'required'    => (bool) $required,
                'priority'    => 240,
                'class'       => array( 'form-row-last' ),
                'value'       => $tax_id,
            ),
        );

        return apply_filters( 'contifico_woocommerce_account_fields', $fields, $required, $user_id );
    }

    /**
     * Normaliza el identificador eliminando caracteres no alfanuméricos.
     *
     * @param string $value Valor a limpiar.
     *
     * @return string
     */
    protected static function clean_identifier( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';

        return preg_replace( '/[^0-9A-Za-z]/', '', $value );
    }
}
