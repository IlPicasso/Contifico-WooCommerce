<?php
if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {
        protected $id;
        protected $parent_id;
        protected $meta = array();
        protected $regular_price = '';

        public function __construct( array $args = array() ) {
            $defaults = array(
                'id'            => 0,
                'parent_id'     => 0,
                'meta'          => array(),
                'regular_price' => '',
            );

            $args = array_merge( $defaults, $args );

            $this->id            = (int) $args['id'];
            $this->parent_id     = (int) $args['parent_id'];
            $this->meta          = is_array( $args['meta'] ) ? $args['meta'] : array();
            $this->regular_price = (string) $args['regular_price'];
        }

        public function get_id() {
            return $this->id;
        }

        public function get_parent_id() {
            return $this->parent_id;
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

        public function get_regular_price() {
            return $this->regular_price;
        }

        public function set_regular_price( $price ) {
            $this->regular_price = (string) $price;
        }

        public function save() {
            // No-op for tests.
        }

        public function get_sku() {
            return isset( $this->meta['_sku'] ) ? $this->meta['_sku'] : '';
        }
    }
}
