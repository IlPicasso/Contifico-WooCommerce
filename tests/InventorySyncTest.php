<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Testable_Contifico_WooCommerce_Sync_Inventory_Sync extends Contifico_WooCommerce_Sync_Inventory_Sync {

    public function __construct( $client = null, $logger = null, $settings = null ) {
        parent::__construct( $client, $logger, $settings );

        if ( null === $this->settings_cache ) {
            $this->settings_cache = array(
                'inventory_price_list' => 'none',
            );
        }
    }

    public function call_resolve_product_id( array $item ) {
        return $this->resolve_product_id( $item );
    }

    public function call_process_inventory_response( array $warehouses, array $args = array() ) {
        return $this->process_inventory_response( $warehouses, $args );
    }

    public function set_client( $client ) {
        $this->client = $client;
    }

    public function set_settings_cache( array $settings ) {
        $this->settings_cache = $settings;
    }

    public function call_maybe_sync_product_prices( array $product_map ) {
        $this->maybe_sync_product_prices( $product_map );
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
            ->twice()
            ->with(\Mockery::type('int'), \Mockery::type('string'), \Mockery::any())
            ->andReturnUsing(
                function ( $product_id, $meta_key, $value ) use ( $timestamp ) {
                    if ( '_contifico_product_id' === $meta_key ) {
                        return 10 === $product_id && 'P-001' === $value;
                    }

                    if ( Contifico_WooCommerce_Sync_Inventory_Sync::META_KEY === $meta_key ) {
                        return 10 === $product_id
                            && isset( $value['main'] )
                            && 'main' === $value['main']['warehouse_id']
                            && abs( 8 - (float) $value['main']['stock'] ) < 0.0001
                            && $timestamp === $value['main']['updated_at_gmt'];
                    }

                    return false;
                }

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
                            'contifico_id'   => 'P-001',

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
        $this->assertSame( array( 10 ), $summary['processed_product_ids'] );
    }

    public function test_process_inventory_response_skips_cleanup_when_disabled() {
        Functions\expect( 'get_posts' )
            ->never();

        $timestamp = '2024-02-01 12:00:00';

        Functions\expect( 'update_post_meta' )
            ->twice()
            ->with(\Mockery::type('int'), \Mockery::type('string'), \Mockery::any())
            ->andReturnUsing(
                function ( $product_id, $meta_key, $value ) use ( $timestamp ) {
                    if ( '_contifico_product_id' === $meta_key ) {
                        return 10 === $product_id && 'PX-99' === $value;
                    }

                    if ( Contifico_WooCommerce_Sync_Inventory_Sync::META_KEY === $meta_key ) {
                        return 10 === $product_id
                            && isset( $value['main'] )
                            && $timestamp === $value['main']['updated_at_gmt'];
                    }

                    return false;
                }

            );

        Functions\expect( 'delete_post_meta' )
            ->never();

        Functions\expect( 'get_post_type' )
            ->once()
            ->with( 10 )
            ->andReturn( 'product' );

        Functions\when( 'wc_stock_amount' )
            ->alias( function ( $value ) {
                return $value;
            } );

        $sync    = new Testable_Contifico_WooCommerce_Sync_Inventory_Sync();
        $summary = $sync->call_process_inventory_response(
            array(
                array(
                    'id'   => 'main',
                    'items' => array(
                        array(
                            'woocommerce_id' => 10,
                            'stock'          => 5,
                            'contifico_id'   => 'PX-99',

                        ),
                    ),
                ),
            ),
            array(
                'clean_stale'        => false,
                'existing_inventory' => array( 10 ),
                'timestamp'          => $timestamp,
            )
        );

        $this->assertSame( 0, $summary['products_cleaned'] );
        $this->assertSame( array( 10 ), $summary['processed_product_ids'] );
    }

    public function test_start_batch_sync_initializes_state_and_schedules_action() {
        Functions\expect( 'get_option' )
            ->once()
            ->with( Contifico_WooCommerce_Sync_Inventory_Sync::BATCH_STATE_OPTION, array() )
            ->andReturn( array() );

        Functions\when( 'apply_filters' )
            ->alias( function ( $hook, $value ) {
                return $value;
            } );

        Functions\expect( 'get_posts' )
            ->once()
            ->andReturn( array( 100 ) );

        Functions\expect( 'current_time' )
            ->once()
            ->with( 'timestamp', true )
            ->andReturn( 123456 );

        Functions\expect( 'update_option' )
            ->once()
            ->with(
                Contifico_WooCommerce_Sync_Inventory_Sync::BATCH_STATE_OPTION,
                \Mockery::on(
                    function ( $value ) {
                        return isset( $value['warehouses']['main'] )
                            && 'Principal' === $value['warehouses']['main']['name']
                            && isset( $value['cleanup_candidates'][100] )
                            && 1 === $value['current_step'];
                    }
                ),
                false
            );

        Functions\expect( 'as_enqueue_async_action' )
            ->once()
            ->with(
                Contifico_WooCommerce_Sync_Inventory_Sync::BATCH_ACTION,
                array( array( 'step' => 1 ) ),
                Contifico_WooCommerce_Sync_Inventory_Sync::ACTION_SCHEDULER_GROUP
            );

        Functions\expect( 'wc_get_logger' )
            ->once()
            ->andReturn( null );

        $client = new class {
            public function get_warehouses() {
                return array(
                    array(
                        'id'     => 'main',
                        'nombre' => 'Principal',
                    ),
                );
            }
        };

        $sync = new Testable_Contifico_WooCommerce_Sync_Inventory_Sync();
        $sync->set_client( $client );

        $result = $sync->start_batch_sync( 'manual' );

        $this->assertIsArray( $result );
        $this->assertSame( 'queued', $result['summary']['status'] );
        $this->assertSame( 1, $result['summary']['warehouses'] );
        $this->assertSame( Contifico_WooCommerce_Sync_Inventory_Sync::DEFAULT_BATCH_SIZE, $result['summary']['batch_size'] );

    }

    public function test_maybe_sync_product_prices_updates_regular_price() {
        $product = new WC_Product(
            array(
                'id'            => 10,
                'regular_price' => '9.99',
                'meta'          => array( '_contifico_product_id' => 'P-001' ),
            )
        );

        $sync = new Testable_Contifico_WooCommerce_Sync_Inventory_Sync();
        $sync->set_settings_cache(
            array(
                'inventory_price_list' => 'pvp1',
            )
        );

        Functions\when( 'wc_format_decimal' )
            ->alias(
                function ( $value, $decimals = 2 ) {
                    return number_format( (float) $value, $decimals, '.', '' );
                }
            );

        Functions\when( 'wc_get_price_decimals' )
            ->justReturn( 2 );

        $GLOBALS['contifico_wc_test_products'][10] = $product;

        $sync->call_maybe_sync_product_prices(
            array(
                10 => array( 'price' => '15.5' ),
            )
        );

        unset( $GLOBALS['contifico_wc_test_products'][10] );
        if ( empty( $GLOBALS['contifico_wc_test_products'] ) ) {
            unset( $GLOBALS['contifico_wc_test_products'] );
        }

        $this->assertSame( '15.50', $product->get_regular_price() );
    }
}

