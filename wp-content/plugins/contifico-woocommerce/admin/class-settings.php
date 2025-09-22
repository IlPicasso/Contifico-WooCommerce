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
        );

        $input = wp_parse_args( (array) $input, $defaults );

        $sanitized = array(
            'api_url'       => esc_url_raw( $input['api_url'] ),
            'api_key'       => sanitize_text_field( $input['api_key'] ),
            'api_secret'    => sanitize_text_field( $input['api_secret'] ),
            'warehouse'     => sanitize_text_field( $input['warehouse'] ),
            'sync_statuses' => array(),
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
        );

        $settings = get_option( $this->option_name, array() );

        return wp_parse_args( $settings, $defaults );
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
