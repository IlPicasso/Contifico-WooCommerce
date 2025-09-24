<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class TaxHelperTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_validate_tax_id_accepts_valid_identifiers() {
        $this->assertTrue( Contifico_WooCommerce_Tax_Helper::validate_tax_id( 'cedula', '0123456789' ) );
        $this->assertTrue( Contifico_WooCommerce_Tax_Helper::validate_tax_id( 'ruc', '1234567890123' ) );
        $this->assertTrue( Contifico_WooCommerce_Tax_Helper::validate_tax_id( 'pasaporte', 'A-12345' ) );
    }

    public function test_validate_tax_id_rejects_invalid_identifiers() {
        $result = Contifico_WooCommerce_Tax_Helper::validate_tax_id( 'cedula', '123' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'contifico_invalid_cedula', $result->get_error_code() );

        $result = Contifico_WooCommerce_Tax_Helper::validate_tax_id( 'ruc', '12345' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'contifico_invalid_ruc', $result->get_error_code() );
    }

    public function test_get_account_fields_includes_user_meta_defaults() {
        Functions\expect( 'get_current_user_id' )
            ->once()
            ->andReturn( 15 );

        Functions\expect( 'get_user_meta' )
            ->times( 4 )
            ->with( 15, \Mockery::type( 'string' ), true )
            ->andReturnUsing(
                function ( $user_id, $meta_key ) {
                    switch ( $meta_key ) {
                        case 'taxpayer_type':
                            return 'J';
                        case 'tax_subject':
                            return 'yes';
                        case 'tax_type':
                            return 'ruc';
                        case 'tax_id':
                            return '1234567890123';
                    }

                    return '';
                }
            );

        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );

        $fields = Contifico_WooCommerce_Tax_Helper::get_account_fields( true );

        $this->assertArrayHasKey( 'taxpayer_type', $fields );
        $this->assertSame( 'J', $fields['taxpayer_type']['value'] );
        $this->assertSame( 'yes', $fields['tax_subject']['value'] );
        $this->assertSame( 'ruc', $fields['tax_type']['value'] );
        $this->assertSame( '1234567890123', $fields['tax_id']['value'] );
    }
}
