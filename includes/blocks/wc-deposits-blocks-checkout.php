<?php 

// woocommerce_store_api_checkout_update_order_meta

Class  WC_Deposits_Store_Api_Checkout_Update_Order_Meta {
    public static function init() {
        add_action( 'woocommerce_store_api_checkout_update_order_meta', [ __CLASS__, 'update_order_meta' ], 20 );
        add_action('woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'check_order_update_request' ], 0);
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ __CLASS__, 'add_payment_request_order_meta' ], 0 );

    }



    public static function add_payment_request_order_meta( $context ) {
        $order = $context->order;

        if($order->get_meta('_wc_deposits_order_has_deposit') !== 'yes')  return;

        if($order->get_meta('_wc_deposits_order_has_deposit') === 'yes') {


            $deposit_payment = wcdp_get_order_partial_payments($order->get_id(),
                array(
                    'meta_key' => '_wc_deposits_payment_type',
                    'meta_value' => 'deposit'
                ));

            // Guard against a mislabeled order with no deposit child — set_order()
            // is typed WC_Order and would TypeError on null.
            if ( empty( $deposit_payment ) || ! ( $deposit_payment[0] instanceof \WC_Order ) ) {
                return;
            }

            $context->set_order($deposit_payment[0]);
        }
    }

    public static function check_order_update_request($order){

        if ( ! $order ) {
            return;
        }

        do_action('woocommerce_deposits_after_create_order', $order);

       
        if($order->get_meta('_wc_deposits_order_has_deposit') !== 'yes') return;

        $payment_method =   $order->get_payment_method();
        
        //create partial payments
        WCDP()->orders->create_order_partial_payments_from_cart($order,$payment_method);

    }

    public static function update_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Only flag orders whose cart actually holds a deposit — mirrors the classic
        // checkout guard (WC_Deposits_Checkout::checkout_update_order_meta). Without
        // this, EVERY block-checkout order was stamped as a deposit order; the payment
        // hook then fetched an empty payment schedule and called set_order(null),
        // fatalling a normal full-price checkout.
        $deposit_enabled = isset( WC()->cart->deposit_info['deposit_enabled'] ) && WC()->cart->deposit_info['deposit_enabled'] === true;
        if ( ! $deposit_enabled ) {
            return;
        }

        $order->add_meta_data('_wc_deposits_order_has_deposit', 'yes', true);

        //update the data
        do_action('woocommerce_deposits_after_create_order', $order);

        $order->save();
    }
}