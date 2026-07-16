<?php
/**
 * The Template for displaying deposit slider on checkout page in checkout-mode
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/wc-deposits-checkout-mode-slider.php.
 *
 * @package DepositWP\Templates
 * @version 4.0.13
 */


?>

<tr  class="deposit-buttons">
    <td colspan="2">
        <div id='<?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>'>

            <div class="<?php echo $basic_buttons ? 'basic-switch-woocommerce-deposits' : 'wcdp-toggle'; ?>">
                <input id='pay-deposit' name='deposit-radio'
                       type='radio' <?php echo checked($default_checked, 'deposit'); ?>
                       class='input-radio<?php echo $basic_buttons ? '' : ' wcdp-toggle__input'; ?>'
                       value='deposit'>
                <label id="pay-deposit-label" class="<?php echo $basic_buttons ? '' : 'wcdp-toggle__option wcdp-toggle__option--deposit'; ?>"
                       for='pay-deposit'><?php echo esc_html__($deposit_text, 'woocommerce-deposits'); ?></label>
                <?php if ($basic_buttons) { ?> <br/> <?php } ?>
                <?php if (isset($force_deposit) && $force_deposit === 'yes') { ?>
                    <input id='pay-full-amount' name='deposit-radio' type='radio'
                           class='input-radio<?php echo $basic_buttons ? '' : ' wcdp-toggle__input'; ?>'
                           disabled>
                    <label id="pay-full-amount-label" class="<?php echo $basic_buttons ? '' : 'wcdp-toggle__option wcdp-toggle__option--full'; ?>"
                           for='pay-full-amount'><?php echo esc_html__($full_text, 'woocommerce-deposits'); ?></label>
                <?php } else { ?>
                    <input id='pay-full-amount' name='deposit-radio'
                           type='radio' <?php echo checked($default_checked, 'full'); ?>
                           class='input-radio<?php echo $basic_buttons ? '' : ' wcdp-toggle__input'; ?>'
                           value='full'>
                    <label id="pay-full-amount-label" class="<?php echo $basic_buttons ? '' : 'wcdp-toggle__option wcdp-toggle__option--full'; ?>"
                           for='pay-full-amount'><?php echo esc_html__($full_text, 'woocommerce-deposits'); ?></label>
                <?php } ?>
            </div>
            <span class='deposit-message' id='wc-deposits-notice'></span>

        </div>

        <?php if ($has_payment_plan && $default_checked === 'deposit') { ?>
            <div id="wcdp-payment-plans">
                <fieldset>
                    <ul>
                        <?php

                        foreach ($payment_plans as $plan_id => $payment_plan) {
                            //if no plan selected , set first plan as selected
                            if (empty($selected_plan)) $selected_plan = $plan_id;
                            ?>
                            <li>

                                <strong>
                                    <input data-id="<?php echo $plan_id; ?>" <?php checked($selected_plan, $plan_id); ?>
                                           type="radio" class="option-input radio" value="<?php echo $plan_id; ?>"
                                           name="wcdp-selected-plan"/>
                                    <?php echo $payment_plan['name']; ?>
                                    <?php
                                    if ($selected_plan == $plan_id) {
                                        //display plan details
                                        $display_plan  = WC()->cart->deposit_info['payment_schedule'];
                                        ?>
                                        <span> <a data-expanded="no"
                                                  data-view-text="<?php echo esc_html__('View details', 'woocommerce-deposits'); ?>"
                                                  data-hide-text="<?php echo esc_html__('Hide details', 'woocommerce-deposits'); ?>"
                                                  data-id="<?php echo $plan_id; ?>"
                                                  class="wcdp-view-plan-details"><?php echo esc_html__('View details', 'woocommerce-deposits'); ?></a>
                                        </span>
                                        <div style="display:none" class="wcdp-single-plan"
                                             id="plan-details-<?php echo $plan_id; ?>">

                                            <?php
                                            $payment_timestamp = current_time('timestamp');
                                            foreach ($display_plan as $payment_timestamp => $plan_line) {
                                                if(isset($plan_line['timestamp'])) $payment_timestamp = $plan_line['timestamp'];
                                                echo '<span>' . wc_price($plan_line['total']) . ' ' . date_i18n(get_option('date_format'), $payment_timestamp) . '</span><br/>';
                                            }


                                            ?>
                                        </div>
                                        <?php

                                    }
                                    ?>


                                </strong>
                            </li>
                        <?php } ?>
                    </ul>
                </fieldset>
            </div>
        <?php } ?>


    </td>
</tr>
