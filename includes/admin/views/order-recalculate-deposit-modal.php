<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
/**
 * @var WC_Order $order
 */
if ($order && $order->get_type() !== 'wcdp_payment') {

    $payment_plans = get_terms(array(
            'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
            'hide_empty' => false
        )
    );

    $handling_options = array(
        'deposit' => esc_html__('with deposit', 'woocommerce-deposits'),
        'split' => esc_html__('Split according to deposit amount', 'woocommerce-deposits'),
        'full' => esc_html__('with future payment(s)', 'woocommerce-deposits')
    );
    $discount_handling_options =  array(
        'deposit' => esc_html__('Deduct from deposit', 'woocommerce-deposits'),
        'split' => esc_html__('Split according to deposit amount', 'woocommerce-deposits'),
        'second_payment' => esc_html__('Deduct from future payment(s)', 'woocommerce-deposits')
    );

    $all_plans = array();
    foreach ($payment_plans as $payment_plan) {
        $all_plans[$payment_plan->term_id] = $payment_plan->name;
    }
    ?>
    <div class="wc-backbone-modal wcdp-recalculate-deposit-modal">
        <div class="wc-backbone-modal-content">

            <section class="wc-backbone-modal-main" role="main">
                <header class="wc-backbone-modal-header">
                    <h1><?php echo esc_html__('Recalculate Deposit', 'woocommerce-deposits'); ?></h1>
                    <button class="modal-close modal-close-link dashicons dashicons-no-alt">
                        <span class="screen-reader-text">Close modal panel</span>
                    </button>
                </header>
                <article>
                    <?php if (wcdp_checkout_mode()) {

                        $deposit_enabled = get_option('wc_deposits_checkout_mode_enabled');
                        $deposit_amount = get_option('wc_deposits_checkout_mode_deposit_amount');
                        $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');

                        ?>
                        <form id="wcdp-modal-recalculate-form" action="" method="post">
                            <table class="widefat">
                                <thead>

                                <tr>
                                    <th><?php echo esc_html__('Enable Deposit', 'woocommerce'); ?></th>
                                    <th><?php echo esc_html__('Amount Type', 'woocommerce-deposits'); ?></th>
                                    <th><?php echo esc_html__('Deposit', 'woocommerce-deposits'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr class="wcdp_calculator_modal_row">
                                    <td><label>
                                            <input <?php echo $deposit_enabled ? 'checked="checked"' : ''; ?> value="yes"
                                                                                                                  name="wc_deposits_deposit_enabled_checkout_mode"
                                                                                                                  class="wcdp_enable_deposit"
                                                                                                                  type="checkbox"/>
                                        </label>
                                    </td>
                                    <td>
                                        <label>
                                            <select class="widefat wc_deposits_deposit_amount_type"
                                                    name="wc_deposits_deposit_amount_type_checkout_mode" <?php echo $deposit_enabled ? '' : 'disabled'; ?> >
                                                <option <?php selected('fixed', $amount_type); ?>
                                                        value="fixed"><?php echo esc_html__('Fixed', 'woocommerce-deposits'); ?></option>
                                                <option <?php selected('percentage', $amount_type); ?>
                                                        value="percentage"><?php echo esc_html__('Percentage', 'woocommerce-deposits'); ?></option>
                                                <option <?php selected('payment_plan', $amount_type); ?>
                                                        value="payment_plan"><?php echo esc_html__('Payment plan', 'woocommerce-deposits'); ?></option>
                                            </select>
                                        </label>
                                    </td>
                                    <td style="min-width: 250px;">
                                        <label>
                                            <input name="wc_deposits_deposit_amount_checkout_mode" <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                   type="number" value="<?php echo $deposit_amount; ?>"
                                                   class="widefat wc_deposits_deposit_amount <?php echo $amount_type === 'payment_plan' ? ' wcdp-hidden' : ''; ?>"/>
                                        </label>
                                        <label>
                                            <select <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                    class="<?php echo $amount_type === 'payment_plan' ? '' : 'wcdp-hidden'; ?> wc_deposits_payment_plan"
                                                    name="wc_deposits_payment_plan_checkout_mode">  <?php
                                                foreach ($all_plans as $key => $plan) {
                                                    ?>
                                                    <option value="<?php echo $key; ?>"><?php echo $plan; ?></option><?php
                                                }
                                                ?>
                                            </select>
                                        </label>
                                    </td>
                                </tr>
                                </tbody>
                                <tfoot>
                                <?php
                                $fees_handling = get_option('wc_deposits_fees_handling','split');
                                $taxes_handling = get_option('wc_deposits_taxes_handling','split');
                                $shipping_handling = get_option('wc_deposits_shipping_handling','split');
                                $shipping_taxes_handling = get_option('wc_deposits_shipping_taxes_handling','split');
                                $discount_from_deposit = get_option('wc_deposits_coupons_handling', 'second_payment');

                                ?>
                                <tr>
                                    <td colspan="3" style=" padding:30px 0 0 0; "><h3
                                                style="margin-bottom: 3px;"><?php echo esc_html__('Additional Settings', 'woocommerce-deposits'); ?></h3>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-left:0;" colspan="2" >
                                        <label for="wc_deposits_fees_handling"><?php echo esc_html__('Fees Collection Method', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_fees_handling">
                                                    <?php
                                                    foreach ($handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option <?php selected($fees_handling,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>
                                <tr>
                                    <td style="padding-left:0;" colspan="2"><label
                                                for="wc_deposits_taxes_handling"><?php echo esc_html__('Taxes Collection Method', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_taxes_handling">
                                                    <?php
                                                    foreach ($handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option  <?php selected($taxes_handling,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>
                                <tr>
                                    <td style="padding-left:0;" colspan="2"><label
                                                for="wc_deposits_shipping_handling"><?php echo esc_html__('Shipping Handling Method', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_shipping_handling">
                                                    <?php
                                                    foreach ($handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option <?php selected($shipping_handling,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>


                                <tr>
                                    <td style="padding-left:0;" colspan="2"><label
                                                for="wc_deposits_coupons_handling"><?php echo esc_html__('Discount Coupons Handling', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_coupons_handling">
                                                    <?php
                                                    foreach ($discount_handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option <?php selected($discount_from_deposit,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>

                                </tfoot>

                            </table>
                        </form>
                        <?php
                    } else {
                        ?>
                        <form id="wcdp-modal-recalculate-form" action="" method="post">
                            <table class="widefat">
                                <thead>

                                <tr>
                                    <th><?php echo esc_html__('Enable Deposit', 'woocommerce'); ?></th>
                                    <th><?php echo esc_html__('Order Item', 'woocommerce'); ?></th>
                                    <th><?php echo esc_html__('Amount Type', 'woocommerce-deposits'); ?></th>
                                    <th><?php esc_html_e('Deposit', 'woocommerce-deposits'); ?></th>
                                </tr>
                                </thead>
                                <tbody>

                                <?php
                                foreach ($order->get_items() as $order_Item) {
                                    $item_data = $order_Item->get_meta('wc_deposit_meta', true);

                                    $deposit_enabled = is_array($item_data) && isset($item_data['enable']) && $item_data['enable'] === 'yes';
                                    $product = $order_Item->get_product();
                                    $amount_type = is_array($item_data) && isset($item_data['deposit']) ? 'fixed' : wc_deposits_get_product_deposit_amount_type($product->get_id());
                                    $deposit_amount = is_array($item_data) && isset($item_data['deposit']) ? $item_data['deposit'] : wc_deposits_get_product_deposit_amount($product->get_id());
                                    if(wc_prices_include_tax() && is_array($item_data) && isset($item_data['tax'])){
                                        $deposit_amount += $item_data['tax'];
                                    }
                                    $deposit_amount = round($deposit_amount,wc_get_price_decimals());

                                    ?>
                                    <tr class="wcdp_calculator_modal_row">

                                        <td><label>
                                                <input <?php echo $deposit_enabled ? 'checked="checked"' : ''; ?>
                                                            value="yes"
                                                            name="wc_deposits_deposit_enabled_<?php echo $order_Item->get_id() ?>"
                                                            class="wcdp_enable_deposit"
                                                            type="checkbox"/>
                                            </label>
                                        </td>
                                        <td><?php echo $order_Item->get_name(); ?></td>
                                        <td>
                                            <label>
                                                <select class="widefat wc_deposits_deposit_amount_type"
                                                        name="wc_deposits_deposit_amount_type_<?php echo $order_Item->get_id() ?>" <?php echo $deposit_enabled ? '' : 'disabled'; ?> >
                                                    <option <?php selected('fixed', $amount_type); ?>
                                                            value="fixed"><?php esc_html_e('Fixed', 'woocommerce-deposits'); ?></option>
                                                    <option <?php selected('percent', $amount_type); ?>
                                                            value="percentage"><?php esc_html_e('Percentage', 'woocommerce-deposits'); ?></option>
                                                    <option <?php selected('payment_plan', $amount_type); ?>
                                                            value="payment_plan"><?php esc_html_e('Payment plan', 'woocommerce-deposits'); ?></option>
                                                </select>
                                            </label>
                                        </td>
                                        <td style="min-width: 250px;">
                                            <label>
                                                <input name="wc_deposits_deposit_amount_<?php echo $order_Item->get_id() ?>" <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                       type="number" value="<?php echo $deposit_amount; ?>"
                                                       class="widefat wc_deposits_deposit_amount <?php echo $amount_type === 'payment_plan' ? ' wcdp-hidden' : ''; ?>"/>
                                            </label>
                                            <label>
                                                <select <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                        class="widefat <?php echo $amount_type === 'payment_plan' ? '' : 'wcdp-hidden'; ?> wc_deposits_payment_plan"
                                                        name="wc_deposits_payment_plan_<?php echo $order_Item->get_id() ?>">  <?php
                                                    foreach ($all_plans as $key => $plan) {
                                                        ?>
                                                        <option
                                                        value="<?php echo $key; ?>"><?php echo $plan; ?></option><?php
                                                    }
                                                    ?>
                                                </select>
                                            </label>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                                </tbody>
                                <tfoot>
                                <?php

                                $fees_handling = get_option('wc_deposits_fees_handling','split');
                                $taxes_handling = get_option('wc_deposits_taxes_handling','split');
                                $shipping_handling = get_option('wc_deposits_shipping_handling','split');
                                $shipping_taxes_handling = get_option('wc_deposits_shipping_taxes_handling','split');
                                $discount_from_deposit = get_option('wc_deposits_coupons_handling', 'second_payment');

                                ?>
                                <tr>
                                    <td colspan="4" style=" padding:30px 0 0 0; "><h3
                                                style="margin-bottom: 3px;"><?php esc_html_e('Additional Settings', 'woocommerce-deposits'); ?></h3>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-left:0;" colspan="3" >
                                        <label for="wc_deposits_fees_handling"><?php esc_html_e('Fees Collection Method', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_fees_handling">
                                                    <?php
                                                    foreach ($handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option <?php selected($fees_handling,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>
                                <tr>
                                    <td style="padding-left:0;" colspan="3"><label
                                                for="wc_deposits_taxes_handling"><?php esc_html_e('Taxes Collection Method', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_taxes_handling">
                                                    <?php
                                                    foreach ($handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option  <?php selected($taxes_handling,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>
                                <tr>
                                    <td style="padding-left:0;" colspan="3"><label
                                                for="wc_deposits_shipping_handling"><?php esc_html_e('Shipping Handling Method', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_shipping_handling">
                                                    <?php
                                                    foreach ($handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option <?php selected($shipping_handling,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>

                                <tr>
                                    <td style="padding-left:0;" colspan="3"><label
                                                for="wc_deposits_coupons_handling"><?php esc_html_e('Discount Coupons Handling', 'woocommerce-deposits') ?></label>
                                    </td>
                                    <td><label>
                                            <select name="wc_deposits_coupons_handling">
                                                    <?php
                                                    foreach ($discount_handling_options as $handling_key => $handling_option) {
                                                        ?>
                                                        <option <?php selected($discount_from_deposit,$handling_key); ?> value="<?php echo $handling_key ?>"> <?php echo $handling_option ?> </option> <?php
                                                    }
                                                    ?>
                                                </select>
                                        </label></td>
                                </tr>

                                </tfoot>

                            </table>
                        </form>
                        <?php
                    } ?>
                </article>
                <footer>
                    <div class="inner">
                        <button id="remove_deposit_data" class=" remove_deposit_data submitdelete button button-secondary button-large"><?php esc_html_e('Remove order deposit data', 'woocommerce-deposits'); ?></button>
                        <button id="btn-ok" class="button button-primary button-large"><?php esc_html_e('Save', 'woocommerce-deposits'); ?></button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
    <div class="wc-backbone-modal-backdrop"></div>
    <?php
}
