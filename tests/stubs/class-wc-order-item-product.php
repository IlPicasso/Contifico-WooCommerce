<?php
if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
    class WC_Order_Item_Product {
        protected $product;
        protected $quantity;
        protected $meta = array();

        public function __construct( array $args = array() ) {
            $defaults = array(
                'product'  => null,
                'quantity' => 0,
                'meta'     => array(),
            );

            $args = array_merge( $defaults, $args );

            $this->product  = $args['product'];
            $this->quantity = $args['quantity'];
            $this->meta     = is_array( $args['meta'] ) ? $args['meta'] : array();
        }

        public function get_product() {
            return $this->product;
        }

        public function get_quantity() {
            return $this->quantity;
        }

        public function get_meta( $key, $single = true ) {
            if ( isset( $this->meta[ $key ] ) ) {
                return $this->meta[ $key ];
            }

            return $single ? '' : array();
        }

        public function set_meta( $key, $value ) {
            $this->meta[ $key ] = $value;
        }
    }
}
