<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        protected $id;
        protected $total;
        protected $meta = array();
        protected $billing_company = '';
        protected $billing_first_name = '';
        protected $billing_last_name = '';
        protected $billing_email = '';
        protected $billing_phone = '';
        protected $billing_address_1 = '';
        protected $billing_address_2 = '';
        protected $billing_country = '';
        protected $items = array();

        public function __construct( array $args = array() ) {
            $defaults = array(
                'id'                 => 0,
                'total'              => 0,
                'meta'               => array(),
                'billing_company'    => '',
                'billing_first_name' => '',
                'billing_last_name'  => '',
                'billing_email'      => '',
                'billing_phone'      => '',
                'billing_address_1'  => '',
                'billing_address_2'  => '',
                'billing_country'    => '',
                'items'              => array(),
            );

            $args = array_merge( $defaults, $args );

            $this->id                 = $args['id'];
            $this->total              = $args['total'];
            $this->meta               = $args['meta'];
            $this->billing_company    = $args['billing_company'];
            $this->billing_first_name = $args['billing_first_name'];
            $this->billing_last_name  = $args['billing_last_name'];
            $this->billing_email      = $args['billing_email'];
            $this->billing_phone      = $args['billing_phone'];
            $this->billing_address_1  = $args['billing_address_1'];
            $this->billing_address_2  = $args['billing_address_2'];
            $this->billing_country    = $args['billing_country'];
            $this->items              = $args['items'];
        }

        public function get_id() {
            return $this->id;
        }

        public function get_total() {
            return $this->total;
        }

        public function get_meta( $key ) {
            return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
        }

        public function get_billing_company() {
            return $this->billing_company;
        }

        public function get_billing_first_name() {
            return $this->billing_first_name;
        }

        public function get_billing_last_name() {
            return $this->billing_last_name;
        }

        public function get_billing_email() {
            return $this->billing_email;
        }

        public function get_billing_phone() {
            return $this->billing_phone;
        }

        public function get_billing_address_1() {
            return $this->billing_address_1;
        }

        public function get_billing_address_2() {
            return $this->billing_address_2;
        }

        public function get_billing_country() {
            return $this->billing_country;
        }

        public function get_items() {
            return $this->items;
        }

        public function get_shipping_total() {
            return 0;
        }

        public function get_shipping_tax() {
            return 0;
        }

        public function get_payment_method() {
            return 'cod';
        }

        public function add_order_note( $note ) {
        }

        public function update_meta_data( $key, $value ) {
        }

        public function save() {
        }
    }
}

class Testable_Invoice_Manager extends Contifico_WooCommerce_Invoice_Manager {

    public function __construct( Contifico_WooCommerce_Admin_Settings $settings, $client ) {
        parent::__construct( $settings, $client );
    }

    public function call_build_invoice_payload( WC_Order $order, array $settings ) {
        return $this->build_invoice_payload( $order, $settings );
    }

    protected function build_vendor_data( array $settings ) {
        return array(
            'ruc'           => '0999999999001',
            'razon_social'  => 'Demo',
            'telefonos'     => '0999999999',
            'direccion'     => 'Main St',
            'tipo'          => 'J',
            'email'         => 'demo@example.com',
            'es_extranjero' => false,
        );
    }

    protected function build_items_payload( WC_Order $order, array $settings, $environment, $foreign_client ) {
        return array(
            'items'          => array(
                array(
                    'producto_id'          => 'P-001',
                    'cantidad'             => 1,
                    'precio'               => 10,
                    'porcentaje_iva'       => 12,
                    'porcentaje_descuento' => 0,
                    'base_cero'            => 0,
                    'base_gravable'        => 10,
                    'base_no_gravable'     => 0,
                ),
            ),
            'subtotal_taxed' => 10,
            'subtotal_0'     => 0,
            'subtotal_free'  => 0,
            'tax_total'      => 1.2,
        );
    }

    protected function build_shipping_payload( WC_Order $order, array $settings, $environment, $foreign_client ) {
        return array();
    }

    protected function build_payment_payload( WC_Order $order, $total, $date ) {
        return array();
    }
}

class InvoiceManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    protected function get_base_settings() {
        return array(
            'invoice_environment'            => 'test',
            'invoice_document_type'          => 'PRE',
            'invoice_test_pos_token'         => 'POS-TOKEN',
            'invoice_sender_tax_id'          => '0999999999001',
            'invoice_sender_name'            => 'Demo Corp',
            'invoice_sender_phone'           => '0999999999',
            'invoice_sender_address'         => 'Main St',
            'invoice_sender_taxpayer_type'   => 'J',
            'invoice_sender_email'           => 'demo@example.com',
        );
    }

    protected function prepare_manager() {
        $settings = new Contifico_WooCommerce_Admin_Settings();
        $client   = \Mockery::mock( 'Contifico_WooCommerce_Api_Contifico_Client' );

        return new Testable_Invoice_Manager( $settings, $client );
    }

    public function test_build_invoice_payload_requires_company_name_for_company_subject() {
        Functions\expect( 'current_time' )
            ->once()
            ->with( 'timestamp' )
            ->andReturn( 1700000000 );

        $order = new WC_Order(
            array(
                'id'                 => 10,
                'total'              => 25.0,
                'billing_first_name' => 'Ada',
                'billing_last_name'  => 'Lovelace',
                'billing_email'      => 'ada@example.com',
                'billing_country'    => 'EC',
                'meta'               => array(
                    '_billing_tax_type'         => 'ruc',
                    '_billing_tax_id'           => '1234567890123',
                    '_billing_taxpayer_type'    => 'J',
                    '_billing_tax_subject'      => 'yes',
                ),
            )
        );

        $manager = $this->prepare_manager();
        $result  = $manager->call_build_invoice_payload( $order, $this->get_base_settings() );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'contifico_missing_company_name', $result->get_error_code() );
    }

    public function test_build_invoice_payload_requires_exterior_type_for_foreign_orders() {
        Functions\expect( 'current_time' )
            ->once()
            ->with( 'timestamp' )
            ->andReturn( 1700000000 );

        $order = new WC_Order(
            array(
                'id'                 => 11,
                'total'              => 30.0,
                'billing_first_name' => 'Grace',
                'billing_last_name'  => 'Hopper',
                'billing_email'      => 'grace@example.com',
                'billing_country'    => 'US',
                'meta'               => array(
                    '_billing_tax_type'         => 'cedula',
                    '_billing_tax_id'           => '1234567890',
                    '_billing_taxpayer_type'    => 'N',
                    '_billing_tax_subject'      => '',
                ),
            )
        );

        $manager = $this->prepare_manager();
        $result  = $manager->call_build_invoice_payload( $order, $this->get_base_settings() );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'contifico_invalid_foreign_tax_type', $result->get_error_code() );
    }

    public function test_build_invoice_payload_rejects_passport_for_ecuador() {
        Functions\expect( 'current_time' )
            ->once()
            ->with( 'timestamp' )
            ->andReturn( 1700000000 );

        $order = new WC_Order(
            array(
                'id'                 => 12,
                'total'              => 18.5,
                'billing_first_name' => 'Sofia',
                'billing_last_name'  => 'Perez',
                'billing_email'      => 'sofia@example.com',
                'billing_country'    => 'EC',
                'meta'               => array(
                    '_billing_tax_type'         => 'pasaporte',
                    '_billing_tax_id'           => 'AB12345',
                    '_billing_taxpayer_type'    => 'N',
                    '_billing_tax_subject'      => '',
                ),
            )
        );

        $manager = $this->prepare_manager();
        $result  = $manager->call_build_invoice_payload( $order, $this->get_base_settings() );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'contifico_invalid_passport_for_ecuador', $result->get_error_code() );
    }
}
