<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ContificoClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    public function test_get_items_uses_default_base_url_when_not_configured() {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'contifico_woocommerce_settings', array() )
            ->andReturn(
                array(
                    'api_url' => '',
                    'api_key' => 'token',
                )
            );

        Functions\expect( 'wp_remote_request' )
            ->once()
            ->with(
                'https://api.contifico.com/sistema/api/v1/producto/',
                \Mockery::on(
                    function ( $args ) {
                        $this->assertSame( 'GET', $args['method'] );
                        $this->assertSame( 'token', $args['headers']['Authorization'] );

                        return true;
                    }
                )
            )
            ->andReturn( array( 'body' => '', 'response' => array( 'code' => 200 ) ) );

        Functions\expect( 'wp_remote_retrieve_response_code' )
            ->once()
            ->with( \Mockery::type( 'array' ) )
            ->andReturn( 200 );

        Functions\expect( 'wp_remote_retrieve_body' )
            ->once()
            ->with( \Mockery::type( 'array' ) )
            ->andReturn( '{"items":[]}' );

        $client = new Contifico_WooCommerce_Api_Contifico_Client();
        $result = $client->get_items();

        $this->assertIsArray( $result );
        $this->assertSame( array( 'items' => array() ), $result );
    }

    public function test_get_items_returns_data_on_success() {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'contifico_woocommerce_settings', array() )
            ->andReturn(
                array(
                    'api_url' => 'https://api.example.com',
                    'api_key' => 'token-123',
                )
            );

        Functions\expect( 'wp_remote_request' )
            ->once()
            ->with(
                'https://api.example.com/producto/?page=2',
                \Mockery::on(
                    function ( $args ) {
                        $this->assertSame( 'GET', $args['method'] );
                        $this->assertSame( 'token-123', $args['headers']['Authorization'] );
                        $this->assertSame( 'application/json', $args['headers']['Accept'] );

                        return true;
                    }
                )
            )
            ->andReturn( array( 'body' => '', 'response' => array( 'code' => 200 ) ) );

        Functions\expect( 'wp_remote_retrieve_response_code' )
            ->once()
            ->with( \Mockery::type( 'array' ) )
            ->andReturn( 200 );

        Functions\expect( 'wp_remote_retrieve_body' )
            ->once()
            ->with( \Mockery::type( 'array' ) )
            ->andReturn( '{"items":[]}' );

        $client = new Contifico_WooCommerce_Api_Contifico_Client();
        $result = $client->get_items( array( 'page' => 2 ) );

        $this->assertIsArray( $result );
        $this->assertSame( array( 'items' => array() ), $result );
    }

    public function test_request_retries_on_transport_error() {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'contifico_woocommerce_settings', array() )
            ->andReturn(
                array(
                    'api_url' => 'https://api.example.com',
                    'api_key' => 'token-123',
                )
            );

        $transport_error = new WP_Error( 'http_request_failed', 'timeout' );

        Functions\expect( 'wp_remote_request' )
            ->times( 3 )
            ->with(
                'https://api.example.com/producto/',
                \Mockery::on(
                    function ( $args ) {
                        return isset( $args['method'] ) && 'GET' === $args['method'];
                    }
                )
            )
            ->andReturn( $transport_error );

        $client = new Contifico_WooCommerce_Api_Contifico_Client();
        $result = $client->get_items();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'contifico_http_request_error', $result->get_error_code() );
    }

    public function test_update_stock_sends_inventory_movement() {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'contifico_woocommerce_settings', array() )
            ->andReturn(
                array(
                    'api_url'   => 'https://api.example.com',
                    'api_key'   => 'token-123',
                    'warehouse' => 'bodega-001',
                )
            );

        $captured_args = null;

        $expected_date = gmdate( 'd/m/Y' );

        Functions\expect( 'wp_remote_request' )
            ->once()
            ->with(
                'https://api.example.com/movimiento-inventario/',
                \Mockery::on(
                    function ( $args ) use ( &$captured_args ) {
                        $captured_args = $args;

                        return isset( $args['method'], $args['headers']['Authorization'] )
                            && 'POST' === $args['method']
                            && 'token-123' === $args['headers']['Authorization'];
                    }
                )
            )
            ->andReturn( array( 'body' => '', 'response' => array( 'code' => 200 ) ) );

        Functions\expect( 'wp_remote_retrieve_response_code' )
            ->once()
            ->with( \Mockery::type( 'array' ) )
            ->andReturn( 200 );

        Functions\expect( 'wp_remote_retrieve_body' )
            ->once()
            ->with( \Mockery::type( 'array' ) )
            ->andReturn( '[]' );

        $client = new Contifico_WooCommerce_Api_Contifico_Client();
        $result = $client->update_stock(
            'PROD123',
            array(
                'cantidad'     => '5.5',
                'descripcion'  => 'Ajuste manual',
            )
        );

        $this->assertIsArray( $result );
        $this->assertNotNull( $captured_args );

        $payload = json_decode( $captured_args['body'], true );

        $this->assertIsArray( $payload );
        $this->assertSame( 'AJU', $payload['tipo'] );
        $this->assertSame( $expected_date, $payload['fecha'] );
        $this->assertSame( 'bodega-001', $payload['bodega_id'] );
        $this->assertSame( 'Ajuste manual', $payload['descripcion'] );
        $this->assertArrayNotHasKey( 'cantidad', $payload );
        $this->assertArrayHasKey( 'detalles', $payload );
        $this->assertCount( 1, $payload['detalles'] );
        $this->assertSame( 'PROD123', $payload['detalles'][0]['producto_id'] );
        $this->assertSame( '5.5', $payload['detalles'][0]['cantidad'] );
    }
}
