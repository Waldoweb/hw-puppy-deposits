<?php
/**
 * Puppy Deposits — Express / Wallet Button Guard
 *
 * Gateway-agnostic replacement for the fragile per-gateway "set the cart total"
 * shims (old wc-ppcp / wc-ppec compatibility). Express / wallet buttons build
 * their payment from the CART before a WC order exists, so the plugin's order
 * substitution can never reach them — historically that meant Apple Pay / Google
 * Pay / PayPal smart buttons silently charged the FULL amount on a deposit cart.
 *
 * Fix: when a deposit is in the cart (or, on a product page, the product offers a
 * deposit) we HIDE the express buttons entirely and force the shopper through
 * standard checkout, where order substitution charges the correct deposit amount.
 * We do this through each gateway's OWN documented disable filter, so there is no
 * markup/CSS scraping and it works for both classic and block checkout.
 *
 * Verified against (POI staging, 2026-07-13):
 *   - WooCommerce PayPal Payments (official PPCP) v4.1.1
 *   - Payment Plugins for PayPal (pymntpl-paypal-woocommerce) v2.0.22
 *   - Payment Plugins for Stripe (woo-stripe-payment) v4.0.6
 *
 * The filters simply never fire when a given gateway is not installed, so this
 * class is safe to load unconditionally.
 *
 * @package DepositWP
 */

namespace DepositWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Express_Payment_Guard {

	public function __construct() {
		/**
		 * Kill switch for the whole guard.
		 *
		 * DANGER: with this disabled, express/wallet buttons price a deposit cart from the
		 * CART (full price) instead of the substituted deposit order. That is the exact
		 * over-charge this class exists to prevent. Intended for diagnosing gateway
		 * behaviour on a sandbox/staging site only — never filter this to false in
		 * production.
		 *
		 * Must be set from an mu-plugin or another plugin loading before this one.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wc_deposits_express_guard_enabled', true ) ) {
			return;
		}

		// --- Official PayPal Payments (PPCP): kills PayPal + Apple/Google Pay smart buttons. ---
		add_filter( 'woocommerce_paypal_payments_buttons_disabled', array( $this, 'ppcp_buttons_disabled' ), 20, 2 );
		add_filter( 'woocommerce_paypal_payments_product_buttons_disabled', array( $this, 'ppcp_product_buttons_disabled' ), 20, 2 );

		// --- Payment Plugins for PayPal: remove every express gateway from the registry. ---
		add_filter( 'wc_ppcp_express_checkout_payment_gateways', array( $this, 'filter_out_express_gateways' ), 20 );

		// --- Payment Plugins for Stripe: remove Apple/Google Pay / Payment Request express methods. ---
		add_filter( 'wc_stripe_express_payment_methods', array( $this, 'filter_out_express_gateways' ), 20 );
	}

	/**
	 * Should express/wallet buttons be suppressed for the CURRENT cart?
	 *
	 * True whenever the cart holds a deposit line (per-product deposit selected, or
	 * checkout-mode deposit radio set). Guarded so it is safe to call before the WC
	 * session/cart are fully available.
	 *
	 * @return bool
	 */
	protected function cart_has_deposit() {
		if ( ! class_exists( __NAMESPACE__ . '\\WC_Deposits_Cart' ) ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		return (bool) WC_Deposits_Cart::is_deposit_in_cart();
	}

	/**
	 * On a single-product page, does the product on display offer a deposit?
	 *
	 * Fail closed: a product-page express button would add-to-cart and check out at
	 * full price in one click, bypassing the deposit choice. So we hide express on
	 * any deposit-eligible product page even before it is in the cart.
	 *
	 * @return bool
	 */
	protected function current_product_is_deposit() {
		if ( ! function_exists( 'wc_deposits_is_product_deposit_enabled' ) ) {
			return false;
		}

		$product_id = 0;

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = get_queried_object_id();
		}

		if ( ! $product_id ) {
			global $product;
			if ( $product instanceof \WC_Product ) {
				$product_id = $product->get_id();
			}
		}

		if ( ! $product_id ) {
			return false;
		}

		// A variable product's variations may individually enable deposits.
		if ( wc_deposits_is_product_deposit_enabled( $product_id ) ) {
			return true;
		}

		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( $product->get_children() as $variation_id ) {
				if ( wc_deposits_is_product_deposit_enabled( $variation_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Combined gate used by the express-registry filters (PayPal / Stripe by
	 * Payment Plugins), which are global per request rather than per context.
	 *
	 * @return bool
	 */
	protected function should_block_express() {
		return $this->cart_has_deposit() || $this->current_product_is_deposit();
	}

	// ---------------------------------------------------------------------
	// Official PPCP (woocommerce-paypal-payments)
	// ---------------------------------------------------------------------

	/**
	 * Context-aware, because PPCP prices its PayPal order differently per context.
	 *
	 * CreateOrderEndpoint does:
	 *     if ( 'pay-now' === $data['context'] ) { ...from_wc_order( $wc_order ) }
	 *     else                                  { ...from_wc_cart( ... ) }
	 *
	 * Our deposit only exists on the substituted WC ORDER — the CART total is left at
	 * full price on purpose (WC_Deposits_Cart::calculate_deposit_totals() records
	 * deposit_info but never calls set_total()). So every cart-priced context (cart,
	 * mini-cart, cart-block, checkout, checkout-block) would hand PayPal the full
	 * amount and must stay blocked. Note this is NOT merely a display issue: the
	 * shopper would approve the full total at PayPal and only later be captured for
	 * the deposit, because OrderProcessor patches from the WC order before capture.
	 *
	 * 'pay-now' — the order-pay page, i.e. the balance-payment link — is built from the
	 * WC order, so PayPal shows and charges precisely that order's total. It is correct
	 * there even if the shopper happens to have another deposit sitting in their cart,
	 * which the old blanket block wrongly killed.
	 *
	 * @param bool        $disabled Current value.
	 * @param string|null $context  PPCP button context (cart, checkout, mini-cart, cart-block, pay-now, ...).
	 * @return bool
	 */
	public function ppcp_buttons_disabled( $disabled, $context = null ) {
		if ( 'pay-now' === $context ) {
			return $disabled; // Priced from the WC order — safe; defer to PPCP.
		}

		return $disabled || $this->cart_has_deposit();
	}

	/**
	 * @param bool  $disabled     Current value.
	 * @param array $context_data PPCP product context data.
	 * @return bool
	 */
	public function ppcp_product_buttons_disabled( $disabled, $context_data = array() ) {
		return $disabled || $this->cart_has_deposit() || $this->current_product_is_deposit();
	}

	// ---------------------------------------------------------------------
	// Payment Plugins for PayPal / Stripe — express gateway registries
	// ---------------------------------------------------------------------

	/**
	 * Return an empty express-gateway list when express must be blocked; otherwise
	 * pass the gateways through untouched.
	 *
	 * @param array $gateways Registered express gateways/methods.
	 * @return array
	 */
	public function filter_out_express_gateways( $gateways ) {
		return $this->should_block_express() ? array() : $gateways;
	}
}

return new Express_Payment_Guard();
