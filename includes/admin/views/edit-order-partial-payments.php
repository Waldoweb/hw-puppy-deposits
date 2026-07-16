<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
/**
 * @var WC_Order $order
 */
if ($order && $order->get_type() !== 'wcdp_payment') {
    $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
    if (!is_array($payment_schedule) || empty($payment_schedule)) {
        ?>
        <div><h4><?php echo esc_html__('No payment schedule found.', 'woocommerce-deposits'); ?></h4></div>

        <?php
    } else {
        ?>
        <table style="width:100%; text-align:left;">
            <thead>
            <tr>
                <th><?php echo esc_html__('Payment', 'woocommerce-deposits'); ?> </th>
                <th><?php echo esc_html__('Date', 'woocommerce-deposits'); ?> </th>
                <th><?php echo esc_html__('Payment method', 'woocommerce-deposits'); ?> </th>
                <th><?php echo esc_html__('Status', 'woocommerce-deposits'); ?> </th>
                <th><?php echo esc_html__('Amount', 'woocommerce-deposits'); ?> </th>
                <th><?php echo esc_html__('Actions', 'woocommerce-deposits'); ?> </th>

            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($payment_schedule as $timestamp => $payment) {

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
                if (isset($payment['id']) && !empty($payment['id'])) $payment_order = wc_get_order($payment['id']);
                if (!$payment_order) continue;
                $gateway = $payment_order ? $payment_order->get_payment_method_title() : '-';
                $payment_id = $payment_order ? '<a href="' . esc_url($payment_order->get_edit_order_url()) . '">' . $payment_order->get_order_number() . '</a>' : '-';
                $status = $payment_order ? wc_get_order_status_name($payment_order->get_status()) : '-';
                $amount =  $payment_order->get_total() - $payment_order->get_total_refunded();
                $price_args = array('currency' => $payment_order->get_currency());

                $actions = array();
                $actions = apply_filters('wc_deposits_admin_partial_payment_actions', $actions, $payment_order, $order->get_id());

                ?>
                <tr>
                    <td><?php echo $payment_id; ?></td>
                    <td><?php echo $date; ?></td>
                    <td><?php echo $gateway; ?></td>
                    <td><?php echo $status; ?></td>
                    <td><?php echo wc_price($amount, $price_args); ?></td>
                    <td>
                        <?php foreach ($actions as $action) {
                            echo $action . "\n\n\n";
                        } ?>

                    </td>


                </tr>
                <?php
            }
            ?>


            </tbody>

        </table>
        <?php //recalculate deposit modal container ?>
        <?php

    }

    ?>

    <script type="text/template" id="tmpl-wcdp-modal-recalculate-deposit">


</script>

    <script>
        jQuery(document).ready(function ($) {
            'use strict';

            function reload_metabox() {

                $('#wc_deposits_partial_payments').block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });

                var data = {
                    action: 'wc_deposits_reload_partial_payments_metabox',
                    order_id: woocommerce_admin_meta_boxes.post_id,
                    security: wc_deposits_data.security
                };

                $.ajax(
                    {
                        url: wc_deposits_data.ajax_url,
                        data: data,
                        type: 'POST',
                        success: function (res) {
                            if (res.success) {

                                $('#wc_deposits_partial_payments div.inside').empty().append(res.data.html);
                                $('#woocommerce-order-items').unblock();
                                $('#wc_deposits_partial_payments').unblock().trigger('wc_deposits_recalculated');
                                $('#wc_deposits_partial_payments').trigger('wc_deposits_recalculated');

                            }
                        }

                    }
                );

            }

            $( document.body ).on ('order-totals-recalculate-complete', function(){
                window.setTimeout(function () {
                    reload_metabox();
                }, 1500);

            } );
            //
            // $('button.button.button-primary.save-action').on('items_saved', function (e) {
            //
            // });




        });

    </script>
    <?php
}
