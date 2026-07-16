<?php
/**
 * The Template for displaying the deposit selector on the checkout page in Checkout Mode.
 *
 * 5.1.0 rebuilt this to use the same card, option rows and toggle as the product page,
 * instead of the bare <input><label><br> list it used to render. The classes below are
 * styled by assets/css/style.css — see the "Reserve card" section there — so the two
 * places a customer picks a deposit now look like the same control.
 *
 * CONTRACT — do not rename: the root's ID, and the pay-deposit / pay-full-amount /
 * wc-deposits-notice IDs, are queried by assets/js/wc-deposits-checkout.js. Note this
 * template is ID-addressed where the product template is class-addressed; that is
 * upstream's split, kept because the JS depends on it.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/wc-deposits-checkout-mode-slider.php.
 *
 * @package DepositWP\Templates
 * @version 5.1.0
 */

$wcdp_full_disabled = ( isset( $force_deposit ) && $force_deposit === 'yes' );
?>

<tr  class="deposit-buttons">
    <td colspan="2">
        <?php // The class mirrors the id: the id is the JS contract, the class is what the
              // stylesheet scopes to (.basic-wc-deposits-options-form .wc-deposits-reserve-card). ?>
        <div id='<?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>'
             class='<?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>'>

            <div class="wc-deposits-reserve-card">
                <?php if ( $basic_buttons ) : ?>

                    <div class="wc-deposits-options">
                        <label id="pay-deposit-label" class="wc-deposits-option deposit-option-row">
                            <input id='pay-deposit' name='deposit-radio' type='radio' class='input-radio'
                                   value='deposit' <?php checked( $default_checked, 'deposit' ); ?>>
                            <span class="wc-deposits-option-title"><?php echo esc_html__( $deposit_text, 'woocommerce-deposits' ); ?></span>
                        </label>
                        <label id="pay-full-amount-label" class="wc-deposits-option full-option-row<?php echo $wcdp_full_disabled ? ' wc-deposits-option--disabled' : ''; ?>">
                            <input id='pay-full-amount' name='deposit-radio' type='radio' class='input-radio'
                                   value='full' <?php checked( $default_checked, 'full' ); ?> <?php disabled( $wcdp_full_disabled ); ?>>
                            <span class="wc-deposits-option-title"><?php echo esc_html__( $full_text, 'woocommerce-deposits' ); ?></span>
                        </label>
                    </div>

                <?php else : ?>

                    <div class="wcdp-card-body">
                        <div class="wcdp-toggle">
                            <input id='pay-deposit' name='deposit-radio' type='radio' class='input-radio wcdp-toggle__input'
                                   value='deposit' <?php checked( $default_checked, 'deposit' ); ?>>
                            <label id="pay-deposit-label" class="wcdp-toggle__option wcdp-toggle__option--deposit"
                                   for='pay-deposit'><?php echo esc_html__( $deposit_text, 'woocommerce-deposits' ); ?></label>
                            <input id='pay-full-amount' name='deposit-radio' type='radio' class='input-radio wcdp-toggle__input'
                                   value='full' <?php checked( $default_checked, 'full' ); ?> <?php disabled( $wcdp_full_disabled ); ?>>
                            <label id="pay-full-amount-label" class="wcdp-toggle__option wcdp-toggle__option--full"
                                   for='pay-full-amount'><?php echo esc_html__( $full_text, 'woocommerce-deposits' ); ?></label>
                        </div>
                    </div>

                <?php endif; ?>
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
