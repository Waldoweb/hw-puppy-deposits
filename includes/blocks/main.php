<?php




add_action( 'woocommerce_blocks_loaded',
	function () {
		require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR. 'wcdp-checkout-block-blocks-integration.php';
		require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR.'wcdp-block-checkout-extend-store-endpoint.php';

		WCDP_Extend_Store_endpoint::init();
		add_action(
			'woocommerce_blocks_cart_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new Wc_Deposits_Blocks_Integration() );
			}
		);
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new Wc_Deposits_Blocks_Integration() );
			}
		);
	}
);



add_action('woocommerce_init',function(){
	require_once __DIR__ . '/wc-deposits-blocks-checkout.php';
	WC_Deposits_Store_Api_Checkout_Update_Order_Meta::init();
});


/**
 * Registers the slug as a block category with WordPress.
 */
function register_woocommerce_deposits_block_category( $categories ) {



	return array_merge(
		$categories,
		[
			[
				'slug'  => 'woocommerce-deposits/blocks',
				'title' => __( 'WooCommerce Deposits Blocks', 'woocommerce-deposits' ),
			],
		]
	); 

}

add_filter( 'block_categories', 'register_woocommerce_deposits_block_category', 10 );



function register_woocommerce_deposits_blocks() {
    // Register the Deposit Slider block
    register_block_type_from_metadata( __DIR__ . '/build/js/deposit-slider/block.json' );
    // Register the Pay Now block
    register_block_type_from_metadata( __DIR__ . '/build/js/partial-payments-details/block.json' );

}
add_action( 'init', 'register_woocommerce_deposits_blocks' );
