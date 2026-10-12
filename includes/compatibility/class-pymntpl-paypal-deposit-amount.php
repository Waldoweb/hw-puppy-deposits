<?php
/**
 * Puppy Deposits — Payment Plugins for PayPal (pymntpl-paypal-woocommerce) deposit amount
 *
 * Makes "Payment Plugins for PayPal WooCommerce" build its PayPal order for the
 * DEPOSIT rather than the full cart total. Companion to the official-PPCP shim in
 * class-ppcp-deposit-amount.php — same disease, this gateway's own hook.
 *
 * The problem
 * -----------
 * When the shopper clicks the PayPal button at checkout, the gateway creates the
 * PayPal order from the CART (PurchaseUnitFactory::from_cart() prices it at
 * $cart->total). The deposit does not exist on the cart — it only appears on the
 * substituted WC order after Place Order. So the shopper was shown and approved the
 * FULL total in the PayPal popup. The gateway does patch the PayPal order to the WC
 * order total before capture (PaymentHandler::get_update_order_params() REPLACEs the
 * purchase unit from from_order()), but PayPal rejects an amount CHANGE once the
 * payment source is confirmed — the same 422 UNPROCESSABLE_ENTITY the official PPCP
 * plugin produced (verified there on staging 2026-07-15, orders #39059-#39061).
 *
 * The fix
 * -------
 * Reprice the purchase unit at creation via the gateway's own
 * 'wc_ppcp_purchase_unit_factory_from_cart' filter: drop the amount to the deposit
 * and surface the not-yet-due balance as additional discount, so the breakdown
 * identity (item_total + shipping + tax + handling − discount == amount) still holds
 * to the cent. The popup then shows the deposit, the shopper approves the deposit,
 * and the gateway's later patch replaces the amount with an identical value — the
 * unchanged-amount patch it already performs on every ordinary checkout.
 *
 * from_order() needs no help: the substituted deposit order and the wcdp_payment
 * balance orders both carry deposit-sized line items, so order-derived pricing
 * (order-pay, the pre-capture patch) is already correct.
 *
 * The filter never fires when the gateway is absent, so this class is safe to load
 * unconditionally.
 *
 * @package DepositWP
 */

namespace DepositWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Pymntpl_PayPal_Deposit_Amount {

	public function __construct() {
		add_filter( 'wc_ppcp_purchase_unit_factory_from_cart', array( $this, 'reprice_to_deposit' ), 20 );
	}

	/**
	 * Reprice a cart-derived purchase unit to the deposit due now.
	 *
	 * @param object $purchase_unit \PaymentPlugins\PayPalSDK\PurchaseUnit built from the cart.
	 * @return object The (possibly repriced) purchase unit.
	 */
	public function reprice_to_deposit( $purchase_unit ) {
		if ( ! is_object( $purchase_unit ) || ! method_exists( $purchase_unit, 'getAmount' ) ) {
			return $purchase_unit;
		}

		$amount = $purchase_unit->getAmount();
		if ( ! $amount ) {
			return $purchase_unit;
		}

		$deposit = \wcdp_cart_deposit_amount();
		if ( null === $deposit || $deposit <= 0 ) {
			return $purchase_unit;
		}

		// Derive the balance from the gateway's own figure so the two agree to the
		// cent regardless of how it rounded the cart total.
		$full    = (float) $amount->getValue();
		$balance = round( $full - $deposit, 2 );
		if ( $balance <= 0 ) {
			return $purchase_unit; // Deposit covers everything; nothing to defer.
		}

		$amount->setValue( round( $full - $balance, 2 ) );

		// Keep PayPal's breakdown identity intact by pushing the deferred balance into
		// the discount component. The breakdown can legitimately be absent (the factory
		// strips it for quantity-less-than-1 carts); an amount without a breakdown is
		// not validated against components, so the new value alone suffices there.
		$breakdown = $amount->getBreakdown();
		if ( $breakdown && $breakdown->getDiscount() ) {
			$discount = $breakdown->getDiscount();
			$discount->setValue( round( (float) $discount->getValue() + $balance, 2 ) );
		}

		return $purchase_unit;
	}
}

return new Pymntpl_PayPal_Deposit_Amount();
