<?php
/**
 * Puppy Deposits — PayPal (PPCP) deposit amount
 *
 * Makes the official "WooCommerce PayPal Payments" (PPCP) plugin build its PayPal
 * order for the DEPOSIT rather than the full cart total.
 *
 * The problem
 * -----------
 * PPCP prices the PayPal order from the CART for every context except 'pay-now'
 * (see CreateOrderEndpoint: 'pay-now' => from_wc_order(), else => from_wc_cart()).
 * Our deposit does not exist on the cart — the cart total stays at full price and the
 * deposit only appears once the order is substituted at checkout. So PPCP created the
 * PayPal order at the full total, the shopper approved the full total, and only then
 * did OrderProcessor try to patch the amount down to the deposit. PayPal rejects a
 * patch that changes the amount of an order whose payment source is already confirmed:
 *
 *     [UNPROCESSABLE_ENTITY] ... failed business validation   (HTTP 422)
 *
 * and PPCP correctly re-throws rather than capture a mismatched amount, so the payment
 * failed outright. Verified on staging 2026-07-15 (orders #39059-#39061).
 *
 * The fix
 * -------
 * PPCP ships a hook for exactly this shape of plugin. AmountFactory::from_wc_cart()
 * does:
 *
 *     $discount_val += $this->extra_discount( 'woocommerce_paypal_payments_cart_extra_discount', $cart );
 *
 * documented as being for plugins that "reduce the WC total directly ... without
 * registering a coupon or fee, making their discount invisible to the standard
 * breakdown getters" (their example is WooCommerce Gift Cards). A deposit is precisely
 * that. We surface the not-yet-due BALANCE as an extra discount, so PPCP derives:
 *
 *     items + shipping + tax - (coupons + balance) == deposit
 *
 * The PayPal order is then created at the deposit amount, the shopper approves the
 * deposit amount, and the later patch finds nothing to change — so the 422 disappears.
 *
 * Only the cart path needs this. The substituted deposit order carries its own
 * deposit-sized line item (subtotal 200 / total 200, not full-price items), so
 * AmountFactory::from_wc_order() already yields the correct amount unaided.
 *
 * @package DepositWP
 */

namespace DepositWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PPCP_Deposit_Amount {

	public function __construct() {
		add_filter( 'woocommerce_paypal_payments_cart_extra_discount', array( $this, 'cart_extra_discount' ), 10, 2 );
	}

	/**
	 * Surface the balance-not-due-now as a discount so PPCP prices the deposit.
	 *
	 * @param float         $discount Discount accumulated so far (from other plugins).
	 * @param \WC_Cart|null $cart     The cart PPCP is pricing.
	 * @return float
	 */
	public function cart_extra_discount( $discount, $cart = null ) {
		$discount = (float) $discount;

		if ( ! function_exists( 'WC' ) ) {
			return $discount;
		}

		$cart = ( $cart instanceof \WC_Cart ) ? $cart : ( WC()->cart ?? null );
		if ( ! $cart instanceof \WC_Cart ) {
			return $discount;
		}

		$deposit = $this->deposit_amount_for_cart();
		if ( null === $deposit || $deposit <= 0 ) {
			return $discount;
		}

		// Mirror AmountFactory::from_wc_cart()'s own arithmetic rather than
		// $cart->get_total(): PPCP derives the PayPal total from these components, and
		// per-item tax rounding can make get_total() differ by a cent. Deriving the
		// balance the same way guarantees the total lands exactly on the deposit.
		$full = (float) $cart->get_subtotal()
			+ (float) $cart->get_fee_total()
			+ (float) $cart->get_shipping_total()
			+ (float) $cart->get_total_tax()
			- (float) $cart->get_discount_total();

		$balance = $full - $deposit;
		if ( $balance <= 0 ) {
			return $discount; // Deposit covers everything; nothing to defer.
		}

		return $discount + $balance;
	}

	/**
	 * The deposit due now for the current cart, or null when this is not a deposit cart.
	 *
	 * @return float|null
	 */
	protected function deposit_amount_for_cart() {
		return \wcdp_cart_deposit_amount();
	}
}

return new PPCP_Deposit_Amount();
