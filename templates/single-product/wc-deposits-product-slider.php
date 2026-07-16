<?php
/**
 * The Template for displaying single product deposit options.
 *
 * Two presentations, chosen by the "Use basic radio buttons" setting:
 *   - Basic radios ON  → flex layout: "{Pay Deposit} ($deposit)" with a smaller
 *     "balance of $X due …" subline, and "{Pay in Full} ($full)".
 *   - Basic radios OFF → the .wcdp-toggle segmented control (styled in assets/css/style.css).
 *
 * Engine/JS hooks are preserved verbatim in both so the add-to-cart engine and
 * add-to-cart.js keep working: the root `.depositwp_single_deposit_form` with
 * data-ajax-refresh / data-product_id, the `{product_id}-deposit-radio` inputs with
 * classes `pay-deposit` / `pay-full-amount`, and `.deposit-message.wc-deposits-notice`.
 * The AJAX handler re-renders this whole template on variation change, so the computed
 * amounts stay in sync.
 *
 * 5.1.0 replaced the vendored "candy" toggle switch here with .wcdp-toggle. The
 * switch-toggle / switch-candy / wc-deposits-switcher classes are gone — nothing in JS
 * ever referenced them; they were purely presentational. The radios are no longer
 * display:none but visually hidden, so they are keyboard-focusable.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/wc-deposits-product-slider.php.
 *
 * @package DepositWP\Templates
 * @version 5.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

do_action( 'wc_deposits_enqueue_product_scripts' );

if ( $force_deposit === 'yes' ) {
    $default_checked = 'deposit';
}

// --- Amounts: deposit due now, full price, and the remaining balance. ---
$full          = (float) $product->get_price();
$deposit_type  = isset( $deposit_info['type'] ) ? $deposit_info['type'] : 'fixed';
$deposit_raw   = isset( $deposit_info['amount'] ) ? (float) $deposit_info['amount'] : 0.0;
$deposit_value = ( 'percent' === $deposit_type ) ? ( $full * $deposit_raw / 100 ) : $deposit_raw;
$balance       = max( 0, $full - $deposit_value );
$has_amounts   = ( $full > 0 && $deposit_value > 0 );

// --- Deposit option second line (configurable; {balance} placeholder). ---
$balance_row_tpl = get_option( 'wc_deposits_deposit_balance_row_text', 'balance of {balance} due at delivery or pickup' );
$balance_row     = '';
if ( $has_amounts && $balance > 0 && '' !== trim( (string) $balance_row_tpl ) ) {
    $balance_row = str_replace( '{balance}', wp_kses_post( wc_price( $balance ) ), esc_html( $balance_row_tpl ) );
}

$pid = $product->get_id();

// Message shown (tooltip) when "Pay in Full" is unavailable because a deposit is forced.
// Blank = no tooltip. Default provided when the option was never saved.
$full_unavailable_text = trim( (string) get_option( 'wc_deposits_full_unavailable_text', __( "Full payment isn't available for this item — a deposit is required to reserve it.", 'woocommerce-deposits' ) ) );

// NB: no styling is computed here any more. Every configurable value is published as a
// --wcdp-* custom property by wcdp_get_deposit_slider_custom_styles() and consumed, with
// its default as the fallback, by the rules in assets/css/style.css. Inline styles were
// also fragile: an external script strips inline font-weight from paragraphs.

// Forced deposit: "Pay in Full" is unselectable. The muted title and not-allowed cursor
// hang off the .wc-deposits-option--disabled class in style.css.
$full_disabled = ( isset( $force_deposit ) && $force_deposit === 'yes' );
?>
<div data-ajax-refresh="<?php echo esc_attr( $ajax_refresh ); ?>" data-product_id="<?php echo esc_attr( $pid ); ?>" class="depositwp_single_deposit_form <?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>">
    <div class="wc-deposits-reserve-card">

        <?php
        // Blank Card Heading = omit the element entirely rather than render an empty <p>.
        // The card is a flex column with a gap, so an empty heading would cost both its own
        // line-height and a gap above the options.
        $wcdp_heading = isset( $deposit_option_text ) ? trim( (string) $deposit_option_text ) : '';
        ?>
        <?php if ( $product->get_type() !== 'grouped' && '' !== $wcdp_heading ) : ?>
            <p class="wc-deposits-reserve-heading"><?php echo esc_html( $wcdp_heading ); ?></p>
        <?php endif; ?>

        <?php if ( $basic_buttons ) : ?>

            <div class="wc-deposits-options">

                <label class="wc-deposits-option deposit-option-row">
                    <input id="<?php echo esc_attr( $pid ); ?>-pay-deposit" class="pay-deposit input-radio" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" value="deposit" <?php checked( $default_checked, 'deposit' ); ?>>
                    <span class="wc-deposits-option-title"><?php echo esc_html( $deposit_text ); ?><?php if ( $has_amounts ) : ?> (<?php echo wp_kses_post( wc_price( $deposit_value ) ); ?>)<?php endif; ?></span>
                    <?php if ( '' !== $balance_row ) : ?>
                        <span class="wc-deposits-option-sub"><?php echo wp_kses_post( $balance_row ); ?></span>
                    <?php endif; ?>
                </label>

                <label class="wc-deposits-option full-option-row<?php echo $full_disabled ? ' wc-deposits-option--disabled' : ''; ?>">
                    <input id="<?php echo esc_attr( $pid ); ?>-pay-full-amount" class="pay-full-amount input-radio" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" value="full" <?php checked( $default_checked, 'full' ); ?> <?php disabled( $full_disabled ); ?>>
                    <span class="wc-deposits-option-title"><?php echo esc_html( $full_text ); ?><?php if ( $full > 0 ) : ?> (<?php echo wp_kses_post( wc_price( $full ) ); ?>)<?php endif; ?></span>
                    <?php if ( $full_disabled && '' !== $full_unavailable_text ) : ?>
                        <span class="wc-deposits-option-sub wc-deposits-option-unavailable"><?php echo esc_html( $full_unavailable_text ); ?></span>
                    <?php endif; ?>
                </label>

            </div>

        <?php else : ?>

            <?php // Padded region under the heading band. The card itself is padding-free so
                  // the band can span edge to edge; this keeps the toggle and message inset. ?>
            <div class="wcdp-card-body">
                <div class="wcdp-toggle">
                <input id="<?php echo esc_attr( $pid ); ?>-pay-deposit" class="pay-deposit input-radio wcdp-toggle__input" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" <?php checked( $default_checked, 'deposit' ); ?> value="deposit">
                <label class="pay-deposit-label wcdp-toggle__option wcdp-toggle__option--deposit" for="<?php echo esc_attr( $pid ); ?>-pay-deposit"><?php echo esc_html( $deposit_text ); ?><?php if ( $has_amounts ) : ?> <span class="wcdp-toggle__price">(<?php echo wp_kses_post( wc_price( $deposit_value ) ); ?>)</span><?php endif; ?></label>
                <input id="<?php echo esc_attr( $pid ); ?>-pay-full-amount" class="pay-full-amount input-radio wcdp-toggle__input" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" <?php checked( $default_checked, 'full' ); ?> <?php disabled( $full_disabled ); ?> value="full">
                <label class="pay-full-amount-label wcdp-toggle__option wcdp-toggle__option--full" for="<?php echo esc_attr( $pid ); ?>-pay-full-amount"><?php echo esc_html( $full_text ); ?><?php if ( $full > 0 ) : ?> <span class="wcdp-toggle__price">(<?php echo wp_kses_post( wc_price( $full ) ); ?>)</span><?php endif; ?></label>
                <?php if ( $full_disabled && '' !== $full_unavailable_text ) : ?>
                    <span class="wcdp-toggle__tip" role="tooltip"><?php echo esc_html( $full_unavailable_text ); ?></span>
                <?php endif; ?>
                </div>
                <span class="deposit-message wc-deposits-notice"></span>
            </div>

        <?php endif; ?>

    </div>
</div>
