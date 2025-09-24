<?php
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        protected $id;
        protected $total;
        protected $meta = array();
        protected $items = array();
        protected $billing = array();
        protected $notes = array();
        protected $order_number = '';

        public function __construct( array $args = array() ) {
            $defaults = array(
                'id'                 => 0,
                'total'              => 0,
                'meta'               => array(),
                'items'              => array(),
                'billing_company'    => '',
                'billing_first_name' => '',
                'billing_last_name'  => '',
                'billing_email'      => '',
                'billing_phone'      => '',
                'billing_address_1'  => '',
                'billing_address_2'  => '',
                'billing_country'    => '',
                'order_number'       => '',
            );

            $args = array_merge( $defaults, $args );

            $this->id           = (int) $args['id'];
            $this->total        = $args['total'];
            $this->meta         = is_array( $args['meta'] ) ? $args['meta'] : array();
            $this->items        = is_array( $args['items'] ) ? $args['items'] : array();
            $this->billing      = array(
                'company'    => $args['billing_company'],
                'first_name' => $args['billing_first_name'],
                'last_name'  => $args['billing_last_name'],
                'email'      => $args['billing_email'],
                'phone'      => $args['billing_phone'],
                'address_1'  => $args['billing_address_1'],
                'address_2'  => $args['billing_address_2'],
                'country'    => $args['billing_country'],
            );
            $this->order_number = (string) $args['order_number'];
        }

        public function get_id() {
            return $this->id;
        }

        public function get_total() {
            return $this->total;
        }

        public function get_meta( $key, $single = true ) {
            if ( isset( $this->meta[ $key ] ) ) {
                return $this->meta[ $key ];
            }

            return $single ? '' : array();
        }

        public function update_meta_data( $key, $value ) {
            $this->meta[ $key ] = $value;
        }

        public function save() {
            // No-op for tests.
        }

        public function add_order_note( $note ) {
            $this->notes[] = $note;
        }

        public function get_notes() {
            return $this->notes;
        }

        public function get_items( $type = 'line_item' ) {
            return $this->items;
        }

        public function set_items( array $items ) {
            $this->items = $items;
        }

        public function get_billing_company() {
            return $this->billing['company'];
        }

        public function get_billing_first_name() {
            return $this->billing['first_name'];
        }

        public function get_billing_last_name() {
            return $this->billing['last_name'];
        }

        public function get_billing_email() {
            return $this->billing['email'];
        }

        public function get_billing_phone() {
            return $this->billing['phone'];
        }

        public function get_billing_address_1() {
            return $this->billing['address_1'];
        }

        public function get_billing_address_2() {
            return $this->billing['address_2'];
        }

        public function get_billing_country() {
            return $this->billing['country'];
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

        public function get_order_number() {
            return '' !== $this->order_number ? $this->order_number : (string) $this->id;
        }
    }
}
