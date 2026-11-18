<?php
/**
 * The REST plumbing the bucket browser registers its routes through.
 *
 * register_rest_route() records what it was asked to register, so a test can
 * assert route shape and -- critically -- that every route carries a
 * permission_callback.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

// phpcs:disable

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [], $override = false ) {
		$GLOBALS['registered_rest_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];

		return true;
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code() {
		return 401;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return substr( md5( 'nonce' . $action ), 0, 10 );
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in. The routes read parameters via ArrayAccess, which is
	 * how WP_REST_Request exposes them.
	 */
	class WP_REST_Request implements ArrayAccess {
		private array $params = [];

		public function set_param( $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_params(): array {
			return $this->params;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public $data = null, public $status = 200, public $headers = [] ) {}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}
