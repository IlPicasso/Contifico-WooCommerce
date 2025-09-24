<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class InventoryTransferManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    protected function build_settings_manager( array $overrides = array() ) {
        $defaults = array(
            'inventory_transfer_enabled'           => 'yes',
            'inventory_transfer_source'            => 'main',
            'inventory_transfer_destination'       => 'web',
            'inventory_transfer_decrease_statuses' => array( 'wc-processing' ),
            'inventory_transfer_restore_statuses'  => array( 'wc-cancelled' ),
        );

        $settings = array_merge( $defaults, $overrides );

        $manager = \Mockery::mock( Contifico_WooCommerce_Admin_Settings::class );
        $manager->shouldReceive( 'get_settings' )
            ->andReturn( $settings );

        return $manager;
    }

    public function test_status_change_triggers_transfer_when_configured() {
        $settings = $this->build_settings_manager();
        $client   = \Mockery::mock( Contifico_WooCommerce_Api_Contifico_Client::class );
        $logger   = \Mockery::mock( 'WC_Logger' );
        $logger->shouldIgnoreMissing();

        Functions\when( 'wc_stock_amount' )
            ->alias( function ( $value ) {
                return (float) $value;
            } );

        Functions\expect( 'current_time' )
            ->once()
            ->with( 'mysql', true )
            ->andReturn( '2024-01-01 00:00:00' );

        $client->shouldReceive( 'create_inventory_transfer' )
            ->once()
            ->with( \Mockery::on( function ( $payload ) {
                return 'main' === $payload['bodega_id']
                    && 'web' === $payload['bodega_destino_id']
                    && 'TRA' === $payload['tipo']
                    && 1 === count( $payload['detalles'] )
                    && 'P-001' === $payload['detalles'][0]['producto_id']
                    && abs( $payload['detalles'][0]['cantidad'] - 2 ) < 0.0001;
            } ) )
            ->andReturn( array( 'codigo' => 'MOV-1' ) );

        $product = new WC_Product(
            array(
                'id'   => 10,
                'meta' => array( '_contifico_product_id' => 'P-001' ),
            )
        );

        $item = new WC_Order_Item_Product(
            array(
                'product'  => $product,
                'quantity' => 2,
                'meta'     => array( '_reduced_stock' => 2 ),
            )
        );

        $order = new WC_Order(
            array(
                'id'    => 123,
                'items' => array( $item ),
            )
        );

        $manager = new Contifico_WooCommerce_Inventory_Transfer_Manager( $settings, $client, $logger );
        $manager->maybe_handle_status_change( 123, 'pending', 'processing', $order );

        $state = $order->get_meta( Contifico_WooCommerce_Inventory_Transfer_Manager::META_KEY );

        $this->assertIsArray( $state );
        $this->assertTrue( $state['reserved'] );
        $this->assertSame( 'MOV-1', $state['last_reference'] );
        $this->assertNotEmpty( $order->get_notes() );
    }

    public function test_restore_transfer_reverts_when_state_exists() {
        $settings = $this->build_settings_manager();
        $client   = \Mockery::mock( Contifico_WooCommerce_Api_Contifico_Client::class );
        $logger   = \Mockery::mock( 'WC_Logger' );
        $logger->shouldIgnoreMissing();

        Functions\expect( 'current_time' )
            ->once()
            ->with( 'mysql', true )
            ->andReturn( '2024-01-02 00:00:00' );

        $client->shouldReceive( 'create_inventory_transfer' )
            ->once()
            ->with( \Mockery::on( function ( $payload ) {
                return 'web' === $payload['bodega_id']
                    && 'main' === $payload['bodega_destino_id']
                    && 1 === count( $payload['detalles'] )
                    && abs( $payload['detalles'][0]['cantidad'] - 2 ) < 0.0001;
            } ) )
            ->andReturn( array( 'codigo' => 'MOV-2' ) );

        $order = new WC_Order(
            array(
                'id'    => 500,
                'items' => array(),
            )
        );

        $order->update_meta_data(
            Contifico_WooCommerce_Inventory_Transfer_Manager::META_KEY,
            array(
                'reserved'    => true,
                'items'       => array(
                    array(
                        'producto_id' => 'P-001',
                        'cantidad'    => 2,
                    ),
                ),
                'source'      => 'main',
                'destination' => 'web',
            )
        );

        $manager = new Contifico_WooCommerce_Inventory_Transfer_Manager( $settings, $client, $logger );
        $manager->maybe_handle_status_change( 500, 'processing', 'cancelled', $order );

        $state = $order->get_meta( Contifico_WooCommerce_Inventory_Transfer_Manager::META_KEY );

        $this->assertIsArray( $state );
        $this->assertFalse( $state['reserved'] );
        $this->assertSame( array(), $state['items'] );
        $this->assertSame( 'MOV-2', $state['last_reference'] );
    }

    public function test_manager_skips_when_feature_disabled() {
        $settings = $this->build_settings_manager(
            array(
                'inventory_transfer_enabled' => 'no',
            )
        );

        $client = \Mockery::mock( Contifico_WooCommerce_Api_Contifico_Client::class );
        $client->shouldReceive( 'create_inventory_transfer' )->never();

        $logger = \Mockery::mock( 'WC_Logger' );
        $logger->shouldIgnoreMissing();

        $order = new WC_Order( array( 'id' => 10 ) );

        $manager = new Contifico_WooCommerce_Inventory_Transfer_Manager( $settings, $client, $logger );
        $manager->maybe_handle_status_change( 10, 'pending', 'processing', $order );

        $this->assertSame( '', $order->get_meta( Contifico_WooCommerce_Inventory_Transfer_Manager::META_KEY ) );
    }
}
