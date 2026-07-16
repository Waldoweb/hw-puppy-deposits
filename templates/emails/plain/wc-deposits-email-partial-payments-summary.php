<?php
/**
 *  Email Original  Order details Summary - Plain
 *
 * This template displays a summary of original order details
 *
 * @package DepositWP\Templates\Plain
 * @version 4.0.15
 */



if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

echo "\n****************************************************\n\n";
esc_html_e('Partial Payments Summary','woocommerce-deposits')."\n";
echo "\n****************************************************\n\n";

foreach($schedule as $timestamp => $payment){

    $date = '';
    if (isset($payment['title'])) {

        $date = $payment['title'];
    } else {
        if(isset($payment['timestamp'])){
            $timestamp = $payment['timestamp'];
        }

        if (!is_numeric($timestamp)) {
            $date = '-';
        } else {
            $date = date_i18n(wc_date_format(), $timestamp);
        }
    }

    $date = apply_filters('wc_deposits_partial_payment_title', $date, $payment);

    $payment_order = false;
    if(isset($payment['id']) && !empty($payment['id'])) $payment_order = wc_get_order($payment['id']);

    if(!$payment_order) continue;
    $payment_id = $payment_order ? $payment_order->get_order_number(): '-';
    $status =  wc_get_order_status_name($payment_order->get_status());
    if($payment_order->get_meta('_wcdp_payment_complete') === 'yes'){
        $status = wc_get_order_status_name('completed');
    }
    $amount = $payment_order ? $payment_order->get_total() : $payment['total'];
    $price_args = array('currency' => $payment_order->get_currency());

    echo esc_html__('Payment','woocommerce-deposits') .": ".esc_html__($date)."\n" ;
    echo esc_html__('Payment ID','woocommerce-deposits') .": ".esc_html__($payment_id)."\n" ;
    echo esc_html__('Status','woocommerce-deposits') .": ".esc_html__($status)."\n" ;
    echo esc_html__('Amount','woocommerce-deposits') .": ".strip_tags($amount,$price_args)."\n";

    echo "\n\n";
}
