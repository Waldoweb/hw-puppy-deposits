<?php

/**
 * The Template for displaying single payment plan display on single product page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/wc-deposits-product-single-plan.php.
 *
 * @package DepositWP\Templates
 * @version 4.6.6
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


$allowed_html = array(
    'strong' => array(),
    'p' => array(),
    'br' => array(),
    'em' => array(),
    'b' => array(),
    's' => array(),
    'strike' => array(),
    'del' => array(),
    'u' => array(),
    'i' => array(),
    'a' => array(
        'target' => array(),
        'href' => array()
    )
);


?>
<li>
    <input
    
    id="plan-<?php echo $plan_id; ?>"
    data-id="<?php echo $plan_id; ?>" <?php echo $count === 0 ? 'checked' : ''; ?>
           type="radio" class="option-input" value="<?php echo $plan_id; ?>"
           name="<?php echo $product->get_id(); ?>-selected-plan"/>
    <label
    for="plan-<?php echo $plan_id; ?>"
    ><?php echo $payment_plan['name']; ?></label>


    <span> <a data-expanded="no"
              data-view-text="<?php esc_html_e('View details', 'woocommerce-deposits'); ?>"
              data-hide-text="<?php esc_html_e('Hide details', 'woocommerce-deposits'); ?>"
              data-id="<?php echo $plan_id; ?>"
              class="wcdp-view-plan-details"><?php esc_html_e('View details', 'woocommerce-deposits'); ?></a></span>

    <div style="display:none" class="wcdp-single-plan plan-details-<?php echo $plan_id; ?>"
    >


        <div>
            <p><?php wp_kses($payment_plan['description'],$allowed_html); ?></p>
        </div>

        <?php if ($product->get_type() !== 'grouped') { ?>


            <div>
                <p><strong><?php esc_html_e('Payments Total', 'woocommerce-deposits'); ?>
                        : <?php echo wc_price($payment_plan['plan_total']); ?></strong></p>
            </div>

            <div>
                <p><?php esc_html_e($deposit_text, 'woocommerce-deposits'); ?>
                    : <?php echo wc_price($payment_plan['deposit_amount']); ?></p>
            </div>

            <table>
                <thead>
                <th><?php esc_html_e('Payment Date', 'woocommerce-deposits') ?></th>
                <th><?php esc_html_e('Amount', 'woocommerce-deposits') ?></th>
                </thead>
                <tbody>
                <?php
                $payment_timestamp = current_time('timestamp');
                foreach ($payment_plan['details']['payment-plan'] as $plan_line) {

                    if (isset($plan_line['date']) && !empty($plan_line['date'])) {
                        $payment_timestamp = strtotime($plan_line['date']);
                    } else {
                        $after = $plan_line['after'];
                        $after_term = $plan_line['after-term'];
                        $payment_timestamp = strtotime(date('Y-m-d', $payment_timestamp) . "+{$plan_line['after']} {$plan_line['after-term']}s");
                    }

                    ?>
                    <tr>
                        <td><?php echo date_i18n(get_option('date_format'), $payment_timestamp) ?></td>
                        <td><?php echo wc_price($plan_line['line_amount']); ?></td>
                    </tr>
                    <?php


                }

                ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
</li>