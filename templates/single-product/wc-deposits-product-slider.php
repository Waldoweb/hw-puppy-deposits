<?php
/**
 * The Template for displaying single product deposit options.
 *
 * Two presentations, chosen by the "Use basic radio buttons" setting:
 *   - Basic radios ON  → Puppy Deposits flex layout: "{Pay Deposit} ($deposit)" with a
 *     smaller "balance of $X due …" subline, and "{Pay in Full} ($full)".
 *   - Basic radios OFF → the original candy toggle switch (styled by assets/css/style.min.css).
 *
 * Engine/JS hooks are preserved verbatim in both so the add-to-cart engine and
 * add-to-cart.js keep working: the root `.depositwp_single_deposit_form` with
 * data-ajax-refresh / data-product_id, the `{product_id}-deposit-radio` inputs with
 * classes `pay-deposit` / `pay-full-amount`, and (toggle mode) `.wc-deposits-switcher`
 * + `.deposit-message.wc-deposits-notice`. The AJAX handler re-renders this whole
 * template on variation change, so the computed amounts stay in sync.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/wc-deposits-product-slider.php.
 *
 * @package DepositWP\Templates
 * @version 5.5.0
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

// --- Styling (all configurable via the Styling settings tab; blank option = the
//     default shown here, which preserves the original look). Colours are stored as
//     sanitised hex / var() / rgb(); sizes as a number, a value with a unit, a var()
//     or calc(). A bare number is treated as px; anything else is used verbatim. ---
$sv_card_bg      = (string) get_option( 'wc_deposits_style_card_bg', '' );
$sv_card_border  = (string) get_option( 'wc_deposits_style_card_border_color', '' );
$sv_card_bw      = (string) get_option( 'wc_deposits_style_card_border_width', '' );
$sv_card_radius  = (string) get_option( 'wc_deposits_style_card_radius', '' );
$sv_card_pad     = (string) get_option( 'wc_deposits_style_card_padding', '' );
$sv_head_color   = (string) get_option( 'wc_deposits_style_heading_color', '' );
$sv_head_size    = (string) get_option( 'wc_deposits_style_heading_size', '' );
$sv_radio_accent = (string) get_option( 'wc_deposits_style_radio_accent', '' );
$sv_opt_color    = (string) get_option( 'wc_deposits_style_option_text_color', '' );
$sv_sub_color    = (string) get_option( 'wc_deposits_style_subline_color', '' );

// Message shown (tooltip) when "Pay in Full" is unavailable because a deposit is forced.
// Blank = no tooltip. Default provided when the option was never saved.
$full_unavailable_text = trim( (string) get_option( 'wc_deposits_full_unavailable_text', __( "Full payment isn't available for this item — a deposit is required to reserve it.", 'woocommerce-deposits' ) ) );

// Resolve a stored size to a CSS length: blank → default; a bare number → append px;
// a value with a unit / var() / calc() / multi-value → use as-is.
$wcdp_css_len = static function ( $val, $default ) {
    if ( '' === $val ) {
        return $default;
    }
    return preg_match( '/^[0-9]+(?:\.[0-9]+)?$/', $val ) ? $val . 'px' : $val;
};

$card_bg      = '' !== $sv_card_bg ? $sv_card_bg : 'rgba(0,0,0,0.03)';
$card_border  = '' !== $sv_card_border ? $sv_card_border : 'rgba(0,0,0,0.12)';
$card_bw      = $wcdp_css_len( $sv_card_bw, '1px' );
$card_radius  = $wcdp_css_len( $sv_card_radius, '8px' );
$card_padding = $wcdp_css_len( $sv_card_pad, '1em 1.25em' );

$card_style = sprintf(
    'margin:1em 0 1.25em;padding:%s;border:%s solid %s;border-radius:%s;background:%s;',
    $card_padding, $card_bw, $card_border, $card_radius, $card_bg
);

// NB: font-weight is intentionally NOT set inline here — it is injected as a CSS rule in
// wcdp_get_deposit_slider_custom_styles() (with !important) so it survives themes/scripts
// that strip inline font-weight from paragraphs and applies at first paint (no flash).
$heading_style = 'margin:0 0 0.75em;';
$heading_style .= 'font-size:' . $wcdp_css_len( $sv_head_size, '1.05em' ) . ';';
if ( '' !== $sv_head_color ) {
    $heading_style .= 'color:' . $sv_head_color . ';';
}

$options_style  = 'display:flex;flex-direction:column;gap:0.75em;';
// Grid: [radio | text]. align-items:center vertically centres the radio on the FIRST
// (title) line; the balance subline drops to row 2, column 2, auto-aligned under the title.
// color:inherit (default) makes the option text match the theme's normal text colour and
// overrides the muted grey the Frames/Automatic.css ".form--light label" rule would apply.
$option_color   = '' !== $sv_opt_color ? $sv_opt_color : 'inherit';
$option_style   = 'display:grid;grid-template-columns:auto 1fr;column-gap:0.6em;align-items:center;margin:0;cursor:pointer;color:' . $option_color . ';';
$radio_style    = 'grid-column:1;grid-row:1;margin:0;align-self:center;';
if ( '' !== $sv_radio_accent ) {
    $radio_style .= 'accent-color:' . $sv_radio_accent . ';';
}
$title_style    = 'grid-column:2;grid-row:1;';
$sub_style      = 'grid-column:2;grid-row:2;font-size:0.85em;margin-top:0.15em;line-height:1.35;';
$sub_style     .= '' !== $sv_sub_color ? 'color:' . $sv_sub_color . ';' : 'opacity:0.7;';

// "Pay in Full" when a deposit is forced: mute the title and make the row un-clickable,
// then explain why in a subline (same slot the deposit option uses for its balance line).
// The muting goes on the TITLE, not the row — a row-level opacity would compound with the
// subline's own 0.7 and leave the explanation too faint to read.
$full_disabled    = ( isset( $force_deposit ) && $force_deposit === 'yes' );
$full_row_style   = $option_style . ( $full_disabled ? 'cursor:not-allowed;' : '' );
$full_title_style = $title_style . ( $full_disabled ? 'opacity:0.55;' : '' );
?>
<div data-ajax-refresh="<?php echo esc_attr( $ajax_refresh ); ?>" data-product_id="<?php echo esc_attr( $pid ); ?>" class="depositwp_single_deposit_form <?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>">
    <div class="wc-deposits-reserve-card" style="<?php echo esc_attr( $card_style ); ?>">

        <?php if ( $product->get_type() !== 'grouped' ) : ?>
            <p class="wc-deposits-reserve-heading" style="<?php echo esc_attr( $heading_style ); ?>"><?php echo esc_html( $deposit_option_text ); ?></p>
        <?php endif; ?>

        <?php if ( $basic_buttons ) : ?>

            <div class="wc-deposits-options" style="<?php echo esc_attr( $options_style ); ?>">

                <label class="wc-deposits-option deposit-option-row" style="<?php echo esc_attr( $option_style ); ?>">
                    <input id="<?php echo esc_attr( $pid ); ?>-pay-deposit" class="pay-deposit input-radio" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" value="deposit" style="<?php echo esc_attr( $radio_style ); ?>" <?php checked( $default_checked, 'deposit' ); ?>>
                    <span class="wc-deposits-option-title" style="<?php echo esc_attr( $title_style ); ?>"><?php echo esc_html( $deposit_text ); ?><?php if ( $has_amounts ) : ?> (<?php echo wp_kses_post( wc_price( $deposit_value ) ); ?>)<?php endif; ?></span>
                    <?php if ( '' !== $balance_row ) : ?>
                        <span class="wc-deposits-option-sub" style="<?php echo esc_attr( $sub_style ); ?>"><?php echo wp_kses_post( $balance_row ); ?></span>
                    <?php endif; ?>
                </label>

                <label class="wc-deposits-option full-option-row<?php echo $full_disabled ? ' wc-deposits-option--disabled' : ''; ?>" style="<?php echo esc_attr( $full_row_style ); ?>">
                    <input id="<?php echo esc_attr( $pid ); ?>-pay-full-amount" class="pay-full-amount input-radio" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" value="full" style="<?php echo esc_attr( $radio_style ); ?>" <?php checked( $default_checked, 'full' ); ?> <?php disabled( $full_disabled ); ?>>
                    <span class="wc-deposits-option-title" style="<?php echo esc_attr( $full_title_style ); ?>"><?php echo esc_html( $full_text ); ?><?php if ( $full > 0 ) : ?> (<?php echo wp_kses_post( wc_price( $full ) ); ?>)<?php endif; ?></span>
                    <?php if ( $full_disabled && '' !== $full_unavailable_text ) : ?>
                        <span class="wc-deposits-option-sub wc-deposits-option-unavailable" style="<?php echo esc_attr( $sub_style ); ?>"><?php echo esc_html( $full_unavailable_text ); ?></span>
                    <?php endif; ?>
                </label>

            </div>

        <?php else : ?>

            <div class="deposit-options switch-toggle switch-candy switch-woocommerce-deposits">
                <input id="<?php echo esc_attr( $pid ); ?>-pay-deposit" class="pay-deposit input-radio" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" <?php checked( $default_checked, 'deposit' ); ?> value="deposit">
                <label class="pay-deposit-label" for="<?php echo esc_attr( $pid ); ?>-pay-deposit"><?php echo esc_html( $deposit_text ); ?><?php if ( $has_amounts ) : ?> <span class="wcdp-price">(<?php echo wp_kses_post( wc_price( $deposit_value ) ); ?>)</span><?php endif; ?></label>
                <input id="<?php echo esc_attr( $pid ); ?>-pay-full-amount" class="pay-full-amount input-radio" name="<?php echo esc_attr( $pid ); ?>-deposit-radio" type="radio" <?php checked( $default_checked, 'full' ); ?> <?php echo ( isset( $force_deposit ) && $force_deposit === 'yes' ) ? 'disabled' : ''; ?> value="full">
                <label class="pay-full-amount-label" for="<?php echo esc_attr( $pid ); ?>-pay-full-amount"><?php echo esc_html( $full_text ); ?><?php if ( $full > 0 ) : ?> <span class="wcdp-price">(<?php echo wp_kses_post( wc_price( $full ) ); ?>)</span><?php endif; ?></label>
                <a class="wc-deposits-switcher"></a>
                <?php if ( isset( $force_deposit ) && $force_deposit === 'yes' && '' !== $full_unavailable_text ) : ?>
                    <span class="wcdp-fulltip" role="tooltip"><?php echo esc_html( $full_unavailable_text ); ?></span>
                <?php endif; ?>
            </div>
            <span class="deposit-message wc-deposits-notice"></span>

        <?php endif; ?>

    </div>
</div>
