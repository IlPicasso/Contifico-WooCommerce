<?php
/**
 * Definición de la página de ajustes del plugin.
 *
 * @package Contifico_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Administra la configuración guardada en la base de datos.
 */
class Contifico_WooCommerce_Admin_Settings {

    /**
     * Nombre del option en la base de datos.
     *
     * @var string
     */
    protected $option_name = 'contifico_woocommerce_settings';

    /**
     * Grupo utilizado para registrar los ajustes.
     *
     * @var string
     */
    protected $option_group = 'contifico_woocommerce';

    /**
     * Inicializa los hooks necesarios para la pantalla de ajustes.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Crea el submenú dentro de WooCommerce.
     *
     * @return void
     */
    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Contifico', 'contifico-woocommerce' ),
            __( 'Contifico', 'contifico-woocommerce' ),
            'manage_woocommerce',
            'contifico-woocommerce',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Registra los ajustes y campos a mostrar en la pantalla de opciones.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            $this->option_group,
            $this->option_name,
            array( $this, 'sanitize' )
        );

        add_settings_section(
            'contifico_woocommerce_api',
            __( 'Credenciales de la API', 'contifico-woocommerce' ),
            array( $this, 'render_section_description' ),
            $this->option_group
        );

        add_settings_field(
            'contifico_woocommerce_api_url',
            __( 'URL de la API', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_api',
            array(
                'label_for'   => 'contifico_woocommerce_api_url',
                'option_key'  => 'api_url',
                'type'        => 'url',
                'placeholder' => 'https://',
                'description' => __( 'Dirección base del servicio de Contifico.', 'contifico-woocommerce' ),
            )
        );

        add_settings_field(
            'contifico_woocommerce_api_key',
            __( 'API Key', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_api',
            array(
                'label_for'  => 'contifico_woocommerce_api_key',
                'option_key' => 'api_key',
                'type'       => 'text',
            )
        );

        add_settings_field(
            'contifico_woocommerce_api_secret',
            __( 'API Secret', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_api',
            array(
                'label_for'  => 'contifico_woocommerce_api_secret',
                'option_key' => 'api_secret',
                'type'       => 'password',
            )
        );

        add_settings_field(
            'contifico_woocommerce_warehouse',
            __( 'Bodega o punto de emisión', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_api',
            array(
                'label_for'   => 'contifico_woocommerce_warehouse',
                'option_key'  => 'warehouse',
                'type'        => 'text',
                'description' => __( 'Identificador de la bodega donde se registrarán las ventas.', 'contifico-woocommerce' ),
            )
        );

        add_settings_section(
            'contifico_woocommerce_sync',
            __( 'Parámetros de sincronización', 'contifico-woocommerce' ),
            '__return_false',
            $this->option_group
        );

        add_settings_field(
            'contifico_woocommerce_sync_statuses',
            __( 'Estados a sincronizar', 'contifico-woocommerce' ),
            array( $this, 'render_statuses_field' ),
            $this->option_group,
            'contifico_woocommerce_sync'
        );

        add_settings_section(
            'contifico_woocommerce_inventory',
            __( 'Sincronización de inventario', 'contifico-woocommerce' ),
            '__return_false',
            $this->option_group
        );

        add_settings_field(
            'contifico_woocommerce_inventory_tools',
            __( 'Acciones disponibles', 'contifico-woocommerce' ),
            array( $this, 'render_inventory_tools_field' ),
            $this->option_group,
            'contifico_woocommerce_inventory'
        );

        add_settings_section(
            'contifico_woocommerce_invoicing',
            __( 'Facturación electrónica', 'contifico-woocommerce' ),
            array( $this, 'render_invoicing_section_description' ),
            $this->option_group
        );

        add_settings_field(
            'contifico_woocommerce_invoice_enabled',
            __( 'Habilitar facturación automática', 'contifico-woocommerce' ),
            array( $this, 'render_checkbox_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_enabled',
                'option_key' => 'invoice_enabled',
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_trigger_status',
            __( 'Estado que genera el documento', 'contifico-woocommerce' ),
            array( $this, 'render_select_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_trigger_status',
                'option_key' => 'invoice_trigger_status',
                'options'    => $this->get_order_status_options(),
                'placeholder' => __( 'Selecciona un estado de pedido…', 'contifico-woocommerce' ),
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_document_type',
            __( 'Tipo de documento a emitir', 'contifico-woocommerce' ),
            array( $this, 'render_select_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_document_type',
                'option_key' => 'invoice_document_type',
                'options'    => array(
                    'FAC' => __( 'Factura', 'contifico-woocommerce' ),
                    'PRE' => __( 'Pre factura', 'contifico-woocommerce' ),
                    'COT' => __( 'Cotización', 'contifico-woocommerce' ),
                ),
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_environment',
            __( 'Ambiente de emisión', 'contifico-woocommerce' ),
            array( $this, 'render_select_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_environment',
                'option_key' => 'invoice_environment',
                'options'    => array(
                    'test'       => __( 'Pruebas', 'contifico-woocommerce' ),
                    'production' => __( 'Producción', 'contifico-woocommerce' ),
                ),
            )
        );

        $this->register_environment_fields( 'test', __( 'Configuración para pruebas', 'contifico-woocommerce' ) );
        $this->register_environment_fields( 'production', __( 'Configuración para producción', 'contifico-woocommerce' ) );

        add_settings_field(
            'contifico_woocommerce_invoice_sender_tax_id',
            __( 'RUC del emisor', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_sender_tax_id',
                'option_key' => 'invoice_sender_tax_id',
                'type'       => 'text',
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_sender_name',
            __( 'Razón social del emisor', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_sender_name',
                'option_key' => 'invoice_sender_name',
                'type'       => 'text',
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_sender_email',
            __( 'Correo electrónico del emisor', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_sender_email',
                'option_key' => 'invoice_sender_email',
                'type'       => 'email',
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_sender_phone',
            __( 'Teléfono del emisor', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_sender_phone',
                'option_key' => 'invoice_sender_phone',
                'type'       => 'text',
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_sender_address',
            __( 'Dirección del emisor', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_sender_address',
                'option_key' => 'invoice_sender_address',
                'type'       => 'text',
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_sender_taxpayer_type',
            __( 'Tipo de contribuyente del emisor', 'contifico-woocommerce' ),
            array( $this, 'render_select_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_sender_taxpayer_type',
                'option_key' => 'invoice_sender_taxpayer_type',
                'options'    => array(
                    'N' => __( 'Persona natural', 'contifico-woocommerce' ),
                    'J' => __( 'Persona jurídica', 'contifico-woocommerce' ),
                ),
            )
        );

        add_settings_field(
            'contifico_woocommerce_invoice_shipping_code',
            __( 'SKU del producto de envío', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => 'contifico_woocommerce_invoice_shipping_code',
                'option_key' => 'invoice_shipping_code',
                'type'       => 'text',
                'description' => __( 'Código del producto en Contifico que representa el envío.', 'contifico-woocommerce' ),
            )
        );
    }

    /**
     * Registra los campos específicos para cada ambiente de facturación.
     *
     * @param string $environment Identificador del ambiente (test o production).
     * @param string $title       Título descriptivo que se mostrará como etiqueta.
     *
     * @return void
     */
    protected function register_environment_fields( $environment, $title ) {
        $environment = (string) $environment;

        add_settings_field(
            "contifico_woocommerce_invoice_{$environment}_heading",
            sprintf( __( 'Parámetros para %s', 'contifico-woocommerce' ), strtolower( $title ) ),
            array( $this, 'render_environment_heading' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'title' => $title,
            )
        );

        add_settings_field(
            "contifico_woocommerce_invoice_{$environment}_token",
            __( 'Token de punto de venta', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => "contifico_woocommerce_invoice_{$environment}_token",
                'option_key' => "invoice_{$environment}_pos_token",
                'type'       => 'text',
            )
        );

        add_settings_field(
            "contifico_woocommerce_invoice_{$environment}_establishment",
            __( 'Código de establecimiento', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => "contifico_woocommerce_invoice_{$environment}_establishment",
                'option_key' => "invoice_{$environment}_establishment",
                'type'       => 'text',
            )
        );

        add_settings_field(
            "contifico_woocommerce_invoice_{$environment}_emission_point",
            __( 'Punto de emisión', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => "contifico_woocommerce_invoice_{$environment}_emission_point",
                'option_key' => "invoice_{$environment}_emission_point",
                'type'       => 'text',
            )
        );

        add_settings_field(
            "contifico_woocommerce_invoice_{$environment}_next_number",
            __( 'Número secuencial siguiente', 'contifico-woocommerce' ),
            array( $this, 'render_text_field' ),
            $this->option_group,
            'contifico_woocommerce_invoicing',
            array(
                'label_for'  => "contifico_woocommerce_invoice_{$environment}_next_number",
                'option_key' => "invoice_{$environment}_next_number",
                'type'       => 'number',
                'description' => __( 'Corresponde al consecutivo que se utilizará para la próxima factura.', 'contifico-woocommerce' ),
            )
        );
    }

    /**
     * Imprime la descripción de la sección de credenciales.
     *
     * @return void
     */
    public function render_section_description() {
        echo '<p>' . esc_html__( 'Introduce los datos proporcionados por Contifico para conectar la tienda.', 'contifico-woocommerce' ) . '</p>';
    }

    /**
     * Imprime la descripción de la sección de facturación.
     *
     * @return void
     */
    public function render_invoicing_section_description() {
        echo '<p>' . esc_html__( 'Configura la emisión de documentos electrónicos y los datos fiscales necesarios para enviarlos a Contifico.', 'contifico-woocommerce' ) . '</p>';
    }

    /**
     * Renderiza un campo de texto genérico.
     *
     * @param array $args Argumentos recibidos desde add_settings_field.
     *
     * @return void
     */
    public function render_text_field( $args ) {
        $defaults = array(
            'label_for'   => '',
            'option_key'  => '',
            'type'        => 'text',
            'placeholder' => '',
            'description' => '',
        );

        $args     = wp_parse_args( $args, $defaults );
        $settings = $this->get_settings();
        $value    = isset( $settings[ $args['option_key'] ] ) ? $settings[ $args['option_key'] ] : '';

        printf(
            '<input type="%1$s" id="%2$s" name="%3$s[%4$s]" value="%5$s" class="regular-text" placeholder="%6$s" />',
            esc_attr( $args['type'] ),
            esc_attr( $args['label_for'] ),
            esc_attr( $this->option_name ),
            esc_attr( $args['option_key'] ),
            esc_attr( $value ),
            esc_attr( $args['placeholder'] )
        );

        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Imprime un encabezado simple para separar grupos de campos de facturación.
     *
     * @param array $args Argumentos del campo.
     *
     * @return void
     */
    public function render_environment_heading( $args ) {
        $title = isset( $args['title'] ) ? $args['title'] : '';

        if ( '' !== $title ) {
            printf( '<p class="description"><strong>%s</strong></p>', esc_html( $title ) );
        }
    }

    /**
     * Renderiza un checkbox con almacenamiento binario.
     *
     * @param array $args Argumentos del campo.
     *
     * @return void
     */
    public function render_checkbox_field( $args ) {
        $defaults = array(
            'label_for'   => '',
            'option_key'  => '',
            'description' => '',
            'label'       => '',
        );

        $args     = wp_parse_args( $args, $defaults );
        $settings = $this->get_settings();
        $value    = isset( $settings[ $args['option_key'] ] ) ? $settings[ $args['option_key'] ] : '';
        $checked  = 'yes' === $value;

        printf(
            '<label><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="yes" %4$s /> %5$s</label>',
            esc_attr( $args['label_for'] ),
            esc_attr( $this->option_name ),
            esc_attr( $args['option_key'] ),
            checked( $checked, true, false ),
            esc_html( $args['label'] )
        );

        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Renderiza un campo select genérico.
     *
     * @param array $args Argumentos del campo.
     *
     * @return void
     */
    public function render_select_field( $args ) {
        $defaults = array(
            'label_for'   => '',
            'option_key'  => '',
            'options'     => array(),
            'placeholder' => '',
            'description' => '',
        );

        $args     = wp_parse_args( $args, $defaults );
        $settings = $this->get_settings();
        $value    = isset( $settings[ $args['option_key'] ] ) ? $settings[ $args['option_key'] ] : '';

        printf(
            '<select id="%1$s" name="%2$s[%3$s]">',
            esc_attr( $args['label_for'] ),
            esc_attr( $this->option_name ),
            esc_attr( $args['option_key'] )
        );

        if ( '' !== $args['placeholder'] ) {
            printf(
                '<option value="">%s</option>',
                esc_html( $args['placeholder'] )
            );
        }

        foreach ( (array) $args['options'] as $option_value => $option_label ) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $option_value ),
                selected( (string) $option_value, (string) $value, false ),
                esc_html( $option_label )
            );
        }

        echo '</select>';

        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Renderiza los checkboxes para seleccionar los estados que se sincronizarán.
     *
     * @return void
     */
    public function render_statuses_field() {
        $settings = $this->get_settings();
        $current  = isset( $settings['sync_statuses'] ) && is_array( $settings['sync_statuses'] ) ? $settings['sync_statuses'] : array();
        $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

        if ( empty( $statuses ) ) {
            echo '<p>' . esc_html__( 'No se encontraron estados de pedido disponibles.', 'contifico-woocommerce' ) . '</p>';
            return;
        }

        foreach ( $statuses as $status_key => $status_label ) {
            printf(
                '<label><input type="checkbox" name="%1$s[sync_statuses][]" value="%2$s" %3$s /> %4$s</label><br />',
                esc_attr( $this->option_name ),
                esc_attr( $status_key ),
                checked( in_array( $status_key, $current, true ), true, false ),
                esc_html( $status_label )
            );
        }
    }

    /**
     * Sanea los datos antes de guardarlos en la base de datos.
     *
     * @param array $input Datos enviados desde el formulario.
     *
     * @return array
     */
    public function sanitize( $input ) {
        $defaults = array(
            'api_url'       => '',
            'api_key'       => '',
            'api_secret'    => '',
            'warehouse'     => '',
            'sync_statuses' => array(),
            'invoice_enabled'                 => '',
            'invoice_trigger_status'          => '',
            'invoice_document_type'           => 'FAC',
            'invoice_environment'             => 'test',
            'invoice_test_pos_token'          => '',
            'invoice_test_establishment'      => '',
            'invoice_test_emission_point'     => '',
            'invoice_test_next_number'        => '',
            'invoice_production_pos_token'    => '',
            'invoice_production_establishment'=> '',
            'invoice_production_emission_point' => '',
            'invoice_production_next_number'  => '',
            'invoice_sender_tax_id'           => '',
            'invoice_sender_name'             => '',
            'invoice_sender_email'            => '',
            'invoice_sender_phone'            => '',
            'invoice_sender_address'          => '',
            'invoice_sender_taxpayer_type'    => 'N',
            'invoice_shipping_code'           => '',
        );

        $input = wp_parse_args( (array) $input, $defaults );

        $sanitized = array(
            'api_url'       => esc_url_raw( $input['api_url'] ),
            'api_key'       => sanitize_text_field( $input['api_key'] ),
            'api_secret'    => sanitize_text_field( $input['api_secret'] ),
            'warehouse'     => sanitize_text_field( $input['warehouse'] ),
            'sync_statuses' => array(),
            'invoice_enabled'                 => ! empty( $input['invoice_enabled'] ) ? 'yes' : 'no',
            'invoice_trigger_status'          => sanitize_text_field( $input['invoice_trigger_status'] ),
            'invoice_document_type'           => in_array( $input['invoice_document_type'], array( 'FAC', 'PRE', 'COT' ), true ) ? $input['invoice_document_type'] : 'FAC',
            'invoice_environment'             => in_array( $input['invoice_environment'], array( 'test', 'production' ), true ) ? $input['invoice_environment'] : 'test',
            'invoice_test_pos_token'          => sanitize_text_field( $input['invoice_test_pos_token'] ),
            'invoice_test_establishment'      => sanitize_text_field( $input['invoice_test_establishment'] ),
            'invoice_test_emission_point'     => sanitize_text_field( $input['invoice_test_emission_point'] ),
            'invoice_test_next_number'        => sanitize_text_field( $input['invoice_test_next_number'] ),
            'invoice_production_pos_token'    => sanitize_text_field( $input['invoice_production_pos_token'] ),
            'invoice_production_establishment'=> sanitize_text_field( $input['invoice_production_establishment'] ),
            'invoice_production_emission_point' => sanitize_text_field( $input['invoice_production_emission_point'] ),
            'invoice_production_next_number'  => sanitize_text_field( $input['invoice_production_next_number'] ),
            'invoice_sender_tax_id'           => sanitize_text_field( $input['invoice_sender_tax_id'] ),
            'invoice_sender_name'             => sanitize_text_field( $input['invoice_sender_name'] ),
            'invoice_sender_email'            => sanitize_email( $input['invoice_sender_email'] ),
            'invoice_sender_phone'            => sanitize_text_field( $input['invoice_sender_phone'] ),
            'invoice_sender_address'          => sanitize_text_field( $input['invoice_sender_address'] ),
            'invoice_sender_taxpayer_type'    => in_array( $input['invoice_sender_taxpayer_type'], array( 'N', 'J' ), true ) ? $input['invoice_sender_taxpayer_type'] : 'N',
            'invoice_shipping_code'           => sanitize_text_field( $input['invoice_shipping_code'] ),
        );

        if ( ! empty( $input['sync_statuses'] ) && is_array( $input['sync_statuses'] ) ) {
            $statuses = array_map( 'sanitize_text_field', $input['sync_statuses'] );
            $sanitized['sync_statuses'] = array_values( array_unique( $statuses ) );
        }

        return $sanitized;
    }

    /**
     * Devuelve los ajustes almacenados en la base de datos.
     *
     * @return array
     */
    public function get_settings() {
        $defaults = array(
            'api_url'       => '',
            'api_key'       => '',
            'api_secret'    => '',
            'warehouse'     => '',
            'sync_statuses' => array(),
            'invoice_enabled'                 => 'no',
            'invoice_trigger_status'          => '',
            'invoice_document_type'           => 'FAC',
            'invoice_environment'             => 'test',
            'invoice_test_pos_token'          => '',
            'invoice_test_establishment'      => '',
            'invoice_test_emission_point'     => '',
            'invoice_test_next_number'        => '',
            'invoice_production_pos_token'    => '',
            'invoice_production_establishment'=> '',
            'invoice_production_emission_point' => '',
            'invoice_production_next_number'  => '',
            'invoice_sender_tax_id'           => '',
            'invoice_sender_name'             => '',
            'invoice_sender_email'            => '',
            'invoice_sender_phone'            => '',
            'invoice_sender_address'          => '',
            'invoice_sender_taxpayer_type'    => 'N',
            'invoice_shipping_code'           => '',
        );

        $settings = get_option( $this->option_name, array() );

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Actualiza un conjunto de ajustes sin perder valores existentes.
     *
     * @param array $values Valores a almacenar.
     *
     * @return void
     */
    public function update_settings( array $values ) {
        $current  = $this->get_settings();
        $filtered = array_merge( $current, $values );

        update_option( $this->option_name, $filtered );
    }

    /**
     * Obtiene la lista de estados de pedido disponibles en WooCommerce.
     *
     * @return array
     */
    protected function get_order_status_options() {
        if ( function_exists( 'wc_get_order_statuses' ) ) {
            return wc_get_order_statuses();
        }

        return array();
    }

    /**
     * Indica si existen credenciales configuradas.
     *
     * @return bool
     */
    public function has_credentials() {
        $settings = $this->get_settings();

        return ! empty( $settings['api_key'] ) && ! empty( $settings['api_secret'] );
    }

    /**
     * Renderiza el contenido de la página de ajustes.
     *
     * @return void
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'contifico-woocommerce' ) );
        }

        $option_group = $this->option_group;
        $this->maybe_render_inventory_notice();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Integración con Contifico', 'contifico-woocommerce' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( $option_group );
                do_settings_sections( $option_group );
                submit_button( __( 'Guardar cambios', 'contifico-woocommerce' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Muestra las herramientas de sincronización manual y la bitácora.
     *
     * @return void
     */
    public function render_inventory_tools_field() {
        if ( ! class_exists( 'Contifico_WooCommerce_Sync_Inventory_Sync' ) ) {
            echo '<p>' . esc_html__( 'La sincronización de inventario no está disponible en este momento.', 'contifico-woocommerce' ) . '</p>';
            return;
        }

        $action_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=contifico_inventory_sync' ),
            'contifico_inventory_sync'
        );

        echo '<p>' . esc_html__( 'Utiliza esta opción para solicitar los saldos de inventario por bodega desde Contifico.', 'contifico-woocommerce' ) . '</p>';
        printf(
            '<p><a href="%1$s" class="button button-secondary">%2$s</a></p>',
            esc_url( $action_url ),
            esc_html__( 'Sincronizar inventario ahora', 'contifico-woocommerce' )
        );

        $log = Contifico_WooCommerce_Sync_Inventory_Sync::get_last_log();

        if ( empty( $log ) ) {
            echo '<p>' . esc_html__( 'Aún no se ha ejecutado ninguna sincronización de inventario.', 'contifico-woocommerce' ) . '</p>';

            return;
        }

        $timestamp = isset( $log['timestamp'] ) ? absint( $log['timestamp'] ) : 0;
        $status    = isset( $log['status'] ) ? $log['status'] : 'success';
        $message   = isset( $log['message'] ) ? $log['message'] : '';
        $details   = isset( $log['data'] ) && is_array( $log['data'] ) ? $log['data'] : array();

        $status_label = 'success' === $status
            ? esc_html__( 'Completada', 'contifico-woocommerce' )
            : esc_html__( 'Con incidencias', 'contifico-woocommerce' );

        $formatted_date = $timestamp
            ? ( function_exists( 'wp_date' )
                ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
                : date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) )
            : esc_html__( 'Sin registro', 'contifico-woocommerce' );

        echo '<div class="contifico-inventory-log">';
        printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Última ejecución:', 'contifico-woocommerce' ), esc_html( $formatted_date ) );
        printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Estado:', 'contifico-woocommerce' ), esc_html( $status_label ) );

        if ( '' !== $message ) {
            printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Mensaje:', 'contifico-woocommerce' ), esc_html( $message ) );
        }

        $summary_items = array();

        if ( isset( $details['context'] ) ) {
            $summary_items[] = sprintf(
                '%s %s',
                esc_html__( 'Origen:', 'contifico-woocommerce' ),
                esc_html( Contifico_WooCommerce_Sync_Inventory_Sync::get_context_description( $details['context'] ) )
            );
        }

        if ( isset( $details['warehouses'] ) ) {
            $summary_items[] = sprintf(
                esc_html__( 'Bodegas procesadas: %d', 'contifico-woocommerce' ),
                (int) $details['warehouses']
            );
        }

        if ( isset( $details['products_updated'] ) ) {
            $summary_items[] = sprintf(
                esc_html__( 'Productos actualizados: %d', 'contifico-woocommerce' ),
                (int) $details['products_updated']
            );
        }

        if ( ! empty( $details['errors'] ) && is_array( $details['errors'] ) ) {
            $summary_items[] = sprintf(
                esc_html__( 'Incidencias reportadas: %d', 'contifico-woocommerce' ),
                count( $details['errors'] )
            );
        }

        if ( ! empty( $summary_items ) ) {
            echo '<ul class="contifico-inventory-log__summary">';

            foreach ( $summary_items as $item ) {
                printf( '<li>%s</li>', esc_html( $item ) );
            }

            echo '</ul>';
        }

        echo '</div>';
    }

    /**
     * Muestra un aviso contextual después de ejecutar la sincronización manual.
     *
     * @return void
     */
    protected function maybe_render_inventory_notice() {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            return;
        }

        if ( ! class_exists( 'Contifico_WooCommerce_Sync_Inventory_Sync' ) ) {
            return;
        }

        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return;
        }

        $notice = Contifico_WooCommerce_Sync_Inventory_Sync::pop_notice_for_user( $user_id );

        if ( empty( $notice ) ) {
            return;
        }

        $class = 'success' === $notice['status'] ? 'notice-success' : 'notice-error';

        printf(
            '<div class="notice %1$s"><p>%2$s</p></div>',
            esc_attr( $class ),
            esc_html( $notice['message'] )
        );
    }
}
