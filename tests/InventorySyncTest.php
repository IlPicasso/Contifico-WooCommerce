<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Testable_Contifico_WooCommerce_Sync_Inventory_Sync extends Contifico_WooCommerce_Sync_Inventory_Sync {

    public function call_resolve_product_id( array $item ) {
        return $this->resolve_product_id( $item );
    }

    public function call_process_inventory_response( array $warehouses ) {
        return $this->process_inventory_response( $warehouses );
    }
}

class InventorySyncTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    public function test_resolve_product_id_accepts_variations() {
        Functions\expect( 'get_post_type' )
            ->once()
            ->with( 321 )
            ->andReturn( 'product_variation' );

        $sync   = new Testable_Contifico_WooCommerce_Sync_Inventory_Sync();
        $result = $sync->call_resolve_product_id( array( 'woocommerce_id' => '321' ) );

        $this->assertSame( 321, $result );
    }

    public function test_process_inventory_response_cleans_stale_metadata() {
        Functions\expect( 'get_posts' )
            ->once()
            ->andReturn( array( 10, 20 ) );

        Functions\expect( 'get_post_type' )
            ->once()
            ->with( 10 )
            ->andReturn( 'product' );

        Functions\when( 'wc_stock_amount' )
            ->alias( function ( $value ) {
                return $value;
            } );

        $timestamp = '2024-01-01 00:00:00';

        Functions\expect( 'current_time' )
            ->once()
            ->with( 'mysql', true )
            ->andReturn( $timestamp );

        Functions\expect( 'update_post_meta' )
            ->once()
            ->with(
                10,
                Contifico_WooCommerce_Sync_Inventory_Sync::META_KEY,
                \Mockery::on(
                    function ( $value ) use ( $timestamp ) {
                        return isset( $value['main'] )
                            && 'main' === $value['main']['warehouse_id']
                            && abs( 8 - (float) $value['main']['stock'] ) < 0.0001
                            && $timestamp === $value['main']['updated_at_gmt'];
                    }
                )
            );

        Functions\expect( 'delete_post_meta' )
            ->once()
            ->with( 20, Contifico_WooCommerce_Sync_Inventory_Sync::META_KEY );

        $sync    = new Testable_Contifico_WooCommerce_Sync_Inventory_Sync();
        $summary = $sync->call_process_inventory_response(
            array(
                array(
                    'id'    => 'main',
                    'nombre' => 'Principal',
                    'items'  => array(
                        array(
                            'woocommerce_id' => 10,
                            'stock'          => 8,
                        ),
                    ),
                ),
            )
        );

        $this->assertSame( 1, $summary['warehouses'] );
        $this->assertSame( 1, $summary['items_processed'] );
        $this->assertSame( 1, $summary['products_updated'] );
        $this->assertSame( 1, $summary['products_cleaned'] );
        $this->assertSame( array(), $summary['errors'] );
    }
}

