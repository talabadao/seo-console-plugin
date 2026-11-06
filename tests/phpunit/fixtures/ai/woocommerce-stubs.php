<?php
/**
 * Minimal WooCommerce stand-ins for PostContext tests. Products are registered in
 * $GLOBALS['seoc_test_wc_products'][ $post_id ] as arrays of getter values.
 *
 * @package SEOConsole
 */

// phpcs:disable

if ( ! class_exists( 'SEOC_Test_WC_Attribute' ) ) {
	class SEOC_Test_WC_Attribute {
		private $name;
		public function __construct( $name ) {
			$this->name = $name;
		}
		public function get_name() {
			return $this->name;
		}
	}
}

if ( ! class_exists( 'SEOC_Test_WC_Product' ) ) {
	class SEOC_Test_WC_Product {
		private $data;
		public function __construct( array $data ) {
			$this->data = $data;
		}
		public function __call( $name, $args ) {
			$key = substr( $name, 4 );
			return isset( $this->data[ $key ] ) ? $this->data[ $key ] : '';
		}
		public function get_attributes() {
			$out = array();
			foreach ( array_keys( isset( $this->data['attributes'] ) ? $this->data['attributes'] : array() ) as $name ) {
				$out[ sanitize_title( $name ) ] = new SEOC_Test_WC_Attribute( $name );
			}
			return $out;
		}
		public function get_attribute( $name ) {
			return isset( $this->data['attributes'][ $name ] ) ? $this->data['attributes'][ $name ] : '';
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) {
		return isset( $GLOBALS['seoc_test_wc_products'][ $id ] ) ? new SEOC_Test_WC_Product( $GLOBALS['seoc_test_wc_products'][ $id ] ) : false;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return 'VND';
	}
}
