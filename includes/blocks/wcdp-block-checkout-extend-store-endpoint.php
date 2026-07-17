<?php
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
/**
 * WooCommerce Points and Rewards Extend Store API.
 *
 * A class to extend the store public API with points and rewards related data.
 *
 * @package WC-Points-Rewards/Classes
 * @since 1.7.0
 */
class WCDP_Extend_Store_endpoint {
	/**
	 * Stores Rest Extending instance.
	 *
	 * @var ExtendRestApi
	 */
	private static $extend;

	/**
	 * Plugin Identifier, unique to each plugin.
	 *
	 * @var string
	 */
	const IDENTIFIER = 'wcdp';

	/**
	 * Bootstraps the class and hooks required data.
	 *
	 */
	public static function init() {
        self::$extend = StoreApi::container()->get( ExtendSchema::class );
		self::extend_store();
	}

	/**
	 * Registers the actual data into each endpoint.
	 *
	 */
	public static function extend_store() {


		if ( is_callable( [ self::$extend, 'register_endpoint_data' ] ) ) {
			self::$extend->register_endpoint_data(
				array(
					'endpoint'        => CheckoutSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'data_callback'   => array( 'WCDP_Extend_Store_endpoint', 'extend_checkout_data' ),
					'schema_callback' => array( 'WCDP_Extend_Store_endpoint', 'extend_checkout_schema' ),
					'schema_type'     => ARRAY_N,
				)
			);
		}

		if ( is_callable( [ self::$extend, 'register_endpoint_data' ] ) ) {
			self::$extend->register_endpoint_data(
				array(
					'endpoint'        => CartSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'data_callback'   => array( 'WCDP_Extend_Store_endpoint', 'extend_checkout_data' ),
					'schema_callback' => array( 'WCDP_Extend_Store_endpoint', 'extend_checkout_schema' ),
					'schema_type'     => ARRAY_N,
				)
			);
		}

		if ( is_callable( [ self::$extend, 'register_update_callback' ] ) ) {
			self::$extend->register_update_callback(
				array(
					'namespace' => self::IDENTIFIER,
					'callback'  => array( 'WCDP_Extend_Store_endpoint', 'rest_recalculate_deposit' ),
				)
			);
		}



	}

	public static function rest_recalculate_deposit($args){

		// This is an unauthenticated Store API callback — validate before writing to
		// the session. The radio is a strict enum; the plan ID must be one the merchant
		// actually enabled for checkout (mirrors the classic guard in
		// WC_Deposits_Checkout, which in_array()-checks against the available plans).
		if ( isset( $args['deposit-radio'] ) ) {
			$radio = sanitize_text_field( $args['deposit-radio'] );
			if ( in_array( $radio, array( 'deposit', 'full' ), true ) ) {
				WC()->session->set( 'deposit-radio', $radio );
			}
		}

		if ( isset( $args['wcdp-selected-plan'] ) ) {
			$selected_plan   = absint( $args['wcdp-selected-plan'] );
			$available_plans = \DepositWP\WC_Deposits_Cart::get_checkout_mode_available_payment_plans();
			if ( $selected_plan && is_array( $available_plans ) && in_array( $selected_plan, array_map( 'absint', $available_plans ), true ) ) {
				WC()->session->set( 'wcdp-selected-plan', $selected_plan );
			}
		}
	}


	/**
	 * Register points and reward data into cart endpoint.
	 *
	 * @return array Registered data or empty array if condition is not satisfied.
	 *
	 */
	public static function extend_checkout_data() {

        $deposit_info = WC()->cart->deposit_info;
		$data['deposit_info'] = $deposit_info;
        $data['currently_selected'] = WC()->session->get('deposit-radio');
		$data['selected_plan'] = WC()->session->get('wcdp-selected-plan');
		
		return $data;
	}

	/**
	 * Register points and rewards schema into cart endpoint.
	 *
	 * @return array Registered schema.
	 *
	 */
	public static function extend_checkout_schema() {
		return [
			'deposit_info' => [
				'type'        => 'object',
				'description' => __( 'Deposit info', 'woocommerce-deposits' ),
				'context'     => [ 'view', 'edit' ],
			],
			'currently_selected' => [
				'type'        => 'string',
				'description' => __( 'Currently selected deposit', 'woocommerce-deposits' ),
				'context'     => [ 'view', 'edit' ],
			],
		];
	}
}



