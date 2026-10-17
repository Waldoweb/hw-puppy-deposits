<?php
/*Copyright: © 2026 Hostetler Web.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace DepositWP;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order, WC_Order_Item_Fee, WC_Geolocation, WP_Error, WCDP_Payment;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * Class WC_Deposits_Orders
 */
class WC_Deposits_Orders
{

    /**
     * Whether the email currently being rendered is the plain-text variant.
     * Set from woocommerce_email_order_details, which passes $plain_text and fires
     * before WC_Emails::order_details() renders the totals we filter.
     *
     * @var bool
     */
    protected $email_is_plain_text = false;

    /**
     * WC_Deposits_Orders constructor.
     */
    public function __construct()
    {

        // Payment complete events
        add_action('woocommerce_order_status_completed', array($this, 'order_status_completed'), 9);
        add_filter('woocommerce_payment_complete_reduce_order_stock', array($this, 'payment_complete_reduce_order_stock'), 10, 2);

        // Order statuses
        add_filter('wc_order_statuses', array($this, 'order_statuses'));
        // Treat the custom "Fully Paid" status as paid (is_paid(), revenue, analytics).
        add_filter('woocommerce_order_is_paid_statuses', array($this, 'add_fully_paid_to_paid_statuses'));
        add_filter('woocommerce_reports_order_statuses', array($this, 'add_fully_paid_to_paid_statuses'));

        // My Account » Orders: show a running deposit balance column.
        add_filter('woocommerce_my_account_my_orders_columns', array($this, 'account_orders_balance_column'), 20);
        add_action('woocommerce_my_account_my_orders_column_wcdp_balance', array($this, 'render_account_orders_balance_column'));
        add_filter('wc_order_is_editable', array($this, 'order_is_editable'), 10, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', array($this, 'valid_order_statuses_for_payment_complete'), 10, 2);
        add_filter('woocommerce_order_has_status', array($this, 'order_has_status'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 3);
        add_filter('woocommerce_order_needs_payment', array($this, 'needs_payment'), 10, 2);

        add_action('before_woocommerce_pay', array($this, 'redirect_legacy_links'));
        // Order handling
        if (!wcdp_checkout_mode()) {
            add_action('woocommerce_new_order_item', array($this, 'add_order_item_meta'), 10, 2);
            add_filter('woocommerce_order_formatted_line_subtotal', array($this, 'order_formatted_line_subtotal'), 10, 3);
        }

        add_filter('woocommerce_payment_complete_order_status', array($this, 'payment_complete_order_status'), 1000, 2);


        add_filter('woocommerce_get_order_item_totals', array($this, 'get_order_item_totals'), 20, 2);

        // Priority 1: must run before WC_Emails::order_details() (priority 10) renders
        // the totals, so get_order_item_totals() knows whether it is building HTML.
        add_action('woocommerce_email_order_details', array($this, 'capture_email_is_plain_text'), 1, 3);

        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hidden_order_item_meta'));

        add_filter('woocommerce_get_checkout_payment_url', array($this, 'checkout_payment_url'), 10, 2);
        add_filter('woocommerce_create_order', array($this, 'create_order'), 10, 2);
        add_action('woocommerce_pre_payment_complete', array($this, 'payment_complete'));


        add_action('woocommerce_thankyou', array($this, 'disable_order_again_for_partial_payments'), 0);
        add_action('woocommerce_order_details_after_order_table', array($this, 'output_myaccount_partial_payments_summary'));

        // Legacy (post-table) order lifecycle. Under HPOS orders are not posts, so
        // these never fire for order operations — the woocommerce_* hooks below cover
        // that case. They are registered only when HPOS is enabled to avoid running
        // the child cleanup twice while posts and orders are kept in sync.
        add_action('delete_post', array($this, 'delete_partial_payments'), 9);
        add_action('wp_trash_post', array($this, 'trash_partial_payments'));
        add_action('untrashed_post', array($this, 'untrash_partial_payments'));

        if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            add_action('woocommerce_before_delete_order', array($this, 'delete_partial_payments'), 9);
            add_action('woocommerce_before_trash_order', array($this, 'trash_partial_payments'));
            add_action('woocommerce_untrash_order', array($this, 'untrash_partial_payments'));
        }

        add_filter('woocommerce_cancel_unpaid_order', array($this, 'cancel_partial_payments'), 10, 2);

        add_filter('pre_trash_post', array($this, 'prevent_user_trash_partial_payments'), 10, 2);
        add_filter('woocommerce_cod_process_payment_order_status', array($this, 'adjust_cod_status_completed'), 10, 2);
        add_action('woocommerce_order_status_partially-paid', 'wc_maybe_reduce_stock_levels');
        add_action('woocommerce_order_status_partially-paid', array($this, 'adjust_second_payment_status'));
        // "Fully Paid" is a custom status, so core stock reduction / coupon counting
        // won't fire on the transition automatically — mirror the partially-paid wiring.
        // wc_maybe_reduce_stock_levels is guarded by _order_stock_reduced against
        // double-reducing when reduce-on-deposit already reduced at partially-paid.
        add_action('woocommerce_order_status_fully-paid', 'wc_maybe_reduce_stock_levels');
        add_action('woocommerce_order_status_fully-paid', 'wc_update_coupon_usage_counts');
        add_filter('woocommerce_order_status_on-hold', array($this, 'set_parent_order_on_hold'));
        add_filter('woocommerce_order_status_failed', array($this, 'set_parent_order_failed'));
        add_filter('woocommerce_order_status_cancelled', array($this, 'set_partial_payments_as_cancelled'));
        add_action('woocommerce_order_status_partially-paid', array($this, 'adjust_booking_status'));
        add_action('wc_deposits_thankyou', array($this, 'output_parent_order_summary'), 10);


        //
        add_filter('woocommerce_locate_template', array($this, 'locate_form_pay_wcdp'), 99, 2);
        add_filter('woocommerce_order_number', array($this, 'partial_payment_number'), 10, 2);

        //update coupon usage restriction
        add_action('woocommerce_order_status_partially-paid', 'wc_update_coupon_usage_counts');

        add_action('woocommerce_checkout_update_order_meta', array($this, 'link_order_item_ids'), 10, 2);

        //link partial payment details to order items
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'set_temporary_key_id'), 20, 4);
        add_action('woocommerce_checkout_create_order_fee_item', array($this, 'set_temporary_key_id'), 20, 4);
        add_action('woocommerce_checkout_create_order_shipping_item', array($this, 'set_temporary_key_id'), 20, 4);
        add_action('woocommerce_checkout_create_order_coupon_item', array($this, 'set_temporary_key_id'), 20, 4);

        add_action('woocommerce_order_status_failed', array($this, 'parent_order_status_failed'));
    }

    /***
     * When parent order is set to failed, we need to set all partial payments to failed to prevent sending out reminders
     * @param $order_id
     * @return void
     */
    function parent_order_status_failed($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'shop_order') {
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order_has_deposit === 'yes') {
                $children = wcdp_get_order_partial_payments($order_id);
                foreach ($children as $child) {
                    if ($child->get_status() !== 'failed') {

                        $child->set_status('failed');
                        $child->save();
                    }
                }
            }

        }
    }

    /**
     * @param $item \WC_Order_Item
     * @param $key
     * @param $values
     * @param $order
     * @return void
     */
    function set_temporary_key_id($item, $key, $values, $order)
    {

        if ($order->get_meta('_wc_deposits_order_has_deposit') !== 'yes') return;
        switch ($item->get_type()) {
            case 'line_item' :
                $item->add_meta_data('_wcdp_tmp_key', $key);
                break;
            case 'fee' :
                $item->update_meta_data('_wcdp_tmp_key', $key);
                break;
            case 'shipping' :
                $item->update_meta_data('_wcdp_tmp_key', $key);
                break;
            case 'coupon':
                $item->update_meta_data('_wcdp_tmp_key', $key);
                break;
            default:
                break;
        }

    }

    function link_order_item_ids($order_id, $data)
    {
        $order = wc_get_order($order_id);
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
        if ($order_has_deposit === 'yes') {

            foreach ($order->get_items(array('line_item', 'fee', 'shipping', 'coupon')) as $item) {
                $key = $item->get_meta('_wcdp_tmp_key');
                switch ($item->get_type()) {
                    case 'line_item' :
                        $this->link_item_ids($item, $key, $order);
                        break;
                    case 'fee' :
                        $this->link_fee_ids($item, $key, $order);
                        break;
                    case 'shipping' :
                        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
                        foreach (WC()->shipping()->get_packages() as $package_key => $package) {
                            if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                                $this->link_shipping_ids($item, $package_key, $package, $order);
                            }
                        }
                        break;
                    case 'coupon':
                        $this->link_coupon_ids($item, $key, $order);
                        break;
                    default:
                        break;
                }
                $item->delete_meta_data('_wcdp_tmp_key');
                $item->save();
            }
            $order->save();
        }


    }

    function link_item_ids($item, $cart_item_key, $order)
    {

        $item_id = $item->get_id();

        $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
        if (!is_array($payment_schedule) || empty($payment_schedule)) return;

        foreach ($payment_schedule as $key => $payment) {

            if (!isset($payment['details'], $payment['details']['items'])) continue;
            foreach ($payment['details']['items'] as $item_key => $item_detail) {
                if ($item_key === $cart_item_key) {
                    //found a match
                    unset($payment_schedule[$key]['details']['items'][$item_key]);
                    $payment_schedule[$key]['details']['items'][$item_id] = $item_detail;
                }
            }
        }
        $order->update_meta_data('_wc_deposits_payment_schedule', $payment_schedule);

    }

    function link_fee_ids($item, $fee_key, $order)
    {

        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {
            $item_id = $item->get_id();

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
            if (!is_array($payment_schedule) || empty($payment_schedule)) return;
            foreach ($payment_schedule as $key => $payment) {

                if (!isset($payment['details'], $payment['details']['fees'])) continue;
                foreach ($payment['details']['fees'] as $item_key => $item_detail) {

                    if ($item_key === $fee_key) {
                        //found a match
                        unset($payment_schedule[$key]['details']['fees'][$item_key]);
                        $payment_schedule[$key]['details']['fees'][$item_id] = $item_detail;
                    }
                }
            }
            $order->update_meta_data('_wc_deposits_payment_schedule', $payment_schedule);
        }
    }

    function link_shipping_ids($item, $package_key, $package, $order)
    {

        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {
            $item_id = $item->get_id();
            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
            if (!is_array($payment_schedule) || empty($payment_schedule)) return;
            //get the chosen rate
            $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
            $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
            foreach ($payment_schedule as $key => $payment) {

                if (!isset($payment['details'], $payment['details']['shipping'])) continue;

                foreach ($payment['details']['shipping'] as $item_key => $item_detail) {
                    if ($item_key === $shipping_rate->id) {
                        //found a match
                        unset($payment_schedule[$key]['details']['shipping'][$item_key]);
                        $payment_schedule[$key]['details']['shipping'][$item_id] = $item_detail;
                    }
                }
            }

            $order->update_meta_data('_wc_deposits_payment_schedule', $payment_schedule);
        }
    }

    function link_coupon_ids($item, $code, $order)
    {

        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {
            $item_id = $item->get_id();
            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
            if (!is_array($payment_schedule) || empty($payment_schedule)) return;
            foreach ($payment_schedule as $key => $payment) {

                if (!isset($payment['details'], $payment['details']['discount'])) continue;
                foreach ($payment['details']['discount'] as $item_key => $item_detail) {

                    if ($item_key === $code) {
                        //found a match
                        unset($payment_schedule[$key]['details']['discount'][$item_key]);
                        $payment_schedule[$key]['details']['discount'][$item_id] = $item_detail;
                    }
                }
            }
            $order->update_meta_data('_wc_deposits_payment_schedule', $payment_schedule);
        }
    }


    function complete_partial_payments($order_id)
    {

        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order->get_type() === 'wcdp_payment') {
            $order->update_status('completed');
            $order->payment_complete();
            $order->save();
        }
    }

    function adjust_second_payment_status($order_id)
    {


        if (wcdp_is_shop_order_screen()) {
            $order = wc_get_order($order_id);

            if (!$order) return;
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule) || count($payment_schedule) > 2) return;

                foreach ($payment_schedule as $payment) {

                    // search for second payment and set it to pending
                    if (isset($payment['id']) && isset($payment['type']) && $payment['type'] !== 'deposit') {

                        $second_payment = wc_get_order($payment['id']);
                        if ($second_payment && !$second_payment->needs_payment()) {
                            $second_payment->set_status('pending');
                            $second_payment->save();
                        }
                    }
                }
            }

        }
    }


    /**
     * @brief allow overriding form-pay.php template to display original order details during partial payment
     * @param $template
     * @param $template_name
     * @param $template_path
     * @return string
     */
    function locate_form_pay_wcdp($template, $template_name)
    {

        if ($template_name === 'checkout/form-pay.php' && get_option('wc_deposits_override_payment_form', 'no') === 'yes') {

            global $wp;
            $order_id = $wp->query_vars['order-pay'];
            $order = wc_get_order($order_id);
            if (!$order) return $template;

            if ($order->get_type() === 'wcdp_payment') {
                $template = WC_DEPOSITS_TEMPLATE_PATH . '/checkout/form-pay.php';
            }
        }


        return $template;

    }

    /**
     * @brief woocommerce bookings compatibility , set bookings to partially-paid when deposit is paid
     */
    function adjust_booking_status($order_id)
    {
        if (method_exists('WC_Booking_Data_Store', 'get_booking_ids_from_order_id')) {
            $booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_id($order_id);
            if (is_array($booking_ids) && !empty($booking_ids)) {
                foreach ($booking_ids as $booking_id) {
                    $booking = new \WC_Booking($booking_id);
                    $booking->set_status('wc-partial-payment');
                    $booking->save();
                }
            }
        }

    }

    function set_partial_payments_as_cancelled($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() !== 'wcdp_payment' && $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes') {

            $partial_payments = wcdp_get_order_partial_payments($order_id);

            foreach ($partial_payments as $single_payment) {

                $single_payment->update_status('cancelled');

            }
        }
    }

    function set_parent_order_failed($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'wcdp_payment' && $order->get_meta('_wc_deposits_payment_type', true) === 'deposit') {
            $parent = wc_get_order($order->get_parent_id());
            if ($parent) {

                $parent->update_status('failed');
                $parent->save();
            }
        }
    }


    function set_parent_order_on_hold($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'wcdp_payment') {
            $parent = wc_get_order($order->get_parent_id());
            if ($parent) {

                // if child order payment method is bacs,  apply it to parent to send the right email instructions
                if ($order->get_payment_method() === 'bacs') {
                    $parent->set_payment_method('bacs');
                }

                $parent->set_status('on-hold');
                $parent->save();
            }
        }
    }

    function adjust_cod_status_completed($status, $order)
    {

        if ($order->get_type() === 'wcdp_payment') {
            $status = 'on-hold';
        }
        return $status;
    }

    function cancel_partial_payments($cancel, $order)
    {
        if ($order->get_type() === 'wcdp_payment') return false;

        return $cancel;

    }

    function prevent_user_trash_partial_payments($trash, $post)
    {

        if (is_object($post) && $post->post_type === 'wcdp_payment') {

            $order = wc_get_order($post->ID);
            if ($order) {
                $parent = wc_get_order($order->get_parent_id());
                if ($parent && $parent->get_status() !== 'trash') {
                    return 'forbidden'; //if value is not null , partial payment won't be trashed
                }
            }
        }

        return $trash;
    }

    /**
     * Whether order storage is HPOS (custom order tables). Child wcdp_payment orders
     * are not posts under HPOS, so wp_trash_post()/wp_delete_post()/wp_untrash_post()
     * do not operate on them — the CRUD path must be used instead.
     *
     * @return bool
     */
    protected function orders_are_hpos()
    {
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Trash / delete / untrash a child payment order by id, using the storage-correct
     * mechanism. $op is 'trash', 'delete' or 'untrash'.
     *
     * @param int    $payment_id
     * @param string $op
     */
    protected function apply_child_payment_op($payment_id, $op)
    {
        $payment_id = absint($payment_id);
        if (!$payment_id) {
            return;
        }

        if ($this->orders_are_hpos()) {
            $child = wc_get_order($payment_id);
            if (!$child) {
                return;
            }
            switch ($op) {
                case 'delete':
                    $child->delete(true); // force delete
                    break;
                case 'trash':
                    $child->delete(false); // HPOS: moves the order to trash
                    break;
                case 'untrash':
                    $data_store = $child->get_data_store();
                    if (method_exists($data_store, 'untrash_order')) {
                        $data_store->untrash_order($child);
                    }
                    break;
            }
            return;
        }

        // Legacy post storage.
        switch ($op) {
            case 'delete':
                wp_delete_post($payment_id, true);
                break;
            case 'trash':
                wp_trash_post($payment_id);
                break;
            case 'untrash':
                wp_untrash_post($payment_id);
                break;
        }
    }

    function untrash_partial_payments($id)
    {

        if (!$id) {
            return;
        }

        $order = wc_get_order($id);

        if ($order && $order->get_type() === 'shop_order') {

            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return;

                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        $this->apply_child_payment_op($payment['id'], 'untrash');
                    }
                }
            }
        }

    }

    function trash_partial_payments($id)
    {

        if (!current_user_can('delete_posts') || !$id) {
            return;
        }

        $order = wc_get_order($id);
        if ($order && $order->get_type() === 'shop_order') {

            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return;

                //temporarily remove filter
                remove_filter('pre_trash_post', array(WCDP()->orders, 'prevent_user_trash_partial_payments'), 10);
                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        $this->apply_child_payment_op($payment['id'], 'trash');
                    }
                }
                add_filter('pre_trash_post', array(WCDP()->orders, 'prevent_user_trash_partial_payments'), 10, 2);

            }
        }

    }

    function delete_partial_payments($id)
    {

        if (!current_user_can('delete_posts') || !$id) {
            return;
        }

        $order = wc_get_order($id);
        if ($order && $order->get_type() === 'shop_order') {

            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return;

                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        $this->apply_child_payment_op($payment['id'], 'delete');
                    }
                }
            }
        }

    }

    function disable_order_again_for_partial_payments($order_id)
    {

        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'wcdp_payment') {
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
            do_action('wc_deposits_thankyou', $order);
            remove_action('woocommerce_order_details_after_order_table', 'woocommerce_order_again_button');
        }

    }

    function output_myaccount_partial_payments_summary($order)
    {
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if (is_account_page() && $order_has_deposit === 'yes' && apply_filters('wc_deposits_myaccount_show_partial_payments_summary', true, $order)) {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
            if (!is_array($payment_schedule)) return;
            wc_get_template(
                'order/wc-deposits-partial-payments-summary.php', array(
                'order_id' => $order->get_id(),
                'schedule' => $payment_schedule
            ),
                '',
                WC_DEPOSITS_TEMPLATE_PATH
            );
        }

    }

    function output_parent_order_summary($partial_payment)
    {


        if ($partial_payment->get_type() === 'wcdp_payment') {
            wc_get_template('order/wc-deposits-order-summary.php', array('partial_payment' => $partial_payment, 'order_id' => $partial_payment->get_parent_id()), '', WC_DEPOSITS_TEMPLATE_PATH);
        }


    }

    function checkout_payment_url($url, $order)
    {

        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
        if ($order_has_deposit === 'yes' && $order->get_type() !== 'wcdp_payment') {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

            if (is_array($payment_schedule) && !empty($payment_schedule)) {


                foreach ($payment_schedule as $payment) {
                    if (!isset($payment['id'])) continue;
                    $payment_order = wc_get_order($payment['id']);

                    if (!$payment_order) continue;//create one

                    if ( !$payment_order->needs_payment() || $payment_order->get_meta('_wcdp_payment_complete')  === 'yes') {
                        continue;
                    }

                    $url = $payment_order->get_checkout_payment_url();
                    $url = add_query_arg(
                        array(
                            'payment' => $payment['type'],
                        ), $url
                    );
                    //already reached a payable payment
                    break;
                }


            }

        }

        return $url;
    }

    function payment_complete($order_id)
    {

        $order = wc_get_order($order_id);
        if (!$order || $order->get_type() !== 'wcdp_payment') return;

        //temporary value while checking for main order
        // the order status for parent is updated at pre_payment_complete so at this point we need to use this meta as flag//temporary value while checking for main order
        $order->update_meta_data('_wcdp_payment_complete', 'yes');

        $order->save();
        $parent_id = $order->get_parent_id();
        $parent = wc_get_order($parent_id);

        if (!$parent) return;

        if ($order->get_meta('_wc_deposits_payment_type', true) === 'deposit') {
            $parent->update_meta_data('_wc_deposits_deposit_paid', 'yes');
            $parent->update_meta_data('_wc_deposits_deposit_payment_time', current_time('timestamp'));
        } else {
            $parent->update_meta_data('_wc_deposits_second_payment_paid', 'yes');
        }
        $parent->save();

        $parent->payment_complete();
        $order->delete_meta_data('_wcdp_payment_complete');
        $order->save();
    }


    function redirect_legacy_links()
    {
        global $wp;
        if (!empty($wp->query_vars['order-pay'])) {
            $order_id = absint($wp->query_vars['order-pay']);
            $order = wc_get_order($order_id);
            if (!$order) return;
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes' && $order->needs_payment()) {

                //make a second check to ensure the order has partial payments

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (is_array($payment_schedule) && !empty($payment_schedule)) {
                    wp_redirect($order->get_checkout_payment_url());
                    exit;
                }
            }
        }
    }

    /**
     * @brief filters whether order can be paid for, based on second payment settings
     * @param $needs_payment
     * @param $order
     * @param $valid_statuses
     * @return bool
     */
    public
    function needs_payment($needs_payment, $order)
    {
        $status = $order->get_status();
        $valid_statuses = wcdp_valid_parent_statuses_for_partial_payment();
        if ($order->get_type() === 'wcdp_payment') {

            $parent = wc_get_order($order->get_parent_id());
            if (!$parent) return false;
            if (is_checkout_pay_page()) {
                try {
                    $payment_type = $order->get_meta('_wc_deposits_payment_type', true);
                    if (($payment_type === 'deposit' && !$parent->needs_payment()) || ($payment_type !== 'deposit' && !in_array($parent->get_status(), $valid_statuses))) {
                        if (did_action('before_woocommerce_pay') && !did_action('after_woocommerce_pay')) {
                            $needs_payment = false;
                            wc_print_notice(sprintf(__('Main order&rsquo;s status is &ldquo;%s&rdquo;&mdash;it cannot be paid for.', 'woocommerce-deposits'), wc_get_order_status_name($parent->get_status())), 'notice');
                        }

                    }
                } catch (\Exception $e) {
                    wc_print_notice($e->getMessage(), 'error');
                }

            }
        }

        if (in_array($status, $valid_statuses)) {
            if (get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {

                $needs_payment = true;
            } else {
                $needs_payment = false;
            }
        }
        return $needs_payment;
    }


    /**
     * @brief hides deposit order item meta from frontend display
     * @param $hidden_meta
     * @return array
     */
    public
    function hidden_order_item_meta($hidden_meta)
    {

        $hidden_meta[] = '_wc_deposits_parent_item_id';
        $hidden_meta[] = 'wc_deposit_meta';
        $hidden_meta[] = '_wcdp_tmp_key';

        return $hidden_meta;

    }

    /**
     * @brief update order meta based on order status change
     * @param $order_id
     * @param $old_status
     * @param $new_status
     */
    public
    function order_status_changed($order_id, $old_status, $new_status)
    {

        $order = wc_get_order($order_id);
        if (!$order) return;
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
        if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

            if (!is_array($payment_schedule) || empty($payment_schedule)) return;


            if ($old_status === 'trash') {


                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        wp_untrash_post($payment['id']);
                    }
                }

            }

            $deposit_paid = $order->get_meta('_wc_deposits_deposit_paid', true);

            $valid_statuses = wcdp_valid_parent_statuses_for_partial_payment();

            //order marked processing /completed manually
            if (in_array($old_status, $valid_statuses) && ($new_status === 'processing' || $new_status === 'completed') && $deposit_paid === 'yes') {

                $order->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                $order->update_meta_data('_wc_deposits_second_payment_paid', 'yes');

                //manually mark deposit partial payment as completed
                foreach ($payment_schedule as $payment) {

                    $partial_payment = wc_get_order($payment['id']);
                    if ($partial_payment) {
                        $partial_payment->set_status('completed');
                        $partial_payment->save();
                    }

                }
            }

            $order->Save();

        }

        // if partial payment status is changed from on-hold to completed
        if ($order->get_type() === 'wcdp_payment' && $order->get_meta('_wc_deposits_payment_type') === 'deposit' && $old_status === 'on-hold' && $new_status === 'completed') {

            $parent = wc_get_order($order->get_parent_id());
            $valid_statuses = wcdp_valid_parent_statuses_for_partial_payment();

            if (!$parent || in_array($parent->get_status(), $valid_statuses)) return;
            if ($order->get_meta('_wc_deposits_payment_type', true) === 'deposit') {
                $parent->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                $parent->update_meta_data('_wc_deposits_deposit_payment_time', current_time('timestamp'));
                $parent->save();
                $parent->payment_complete();
            }
        }
    }


    /**
     * @brief update order meta when order is marked completed
     * @param $order_id
     */

    public
    function order_status_completed($order_id)
    {

        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order->get_type() === 'wcdp_payment') {
            //exclude manual editing of parent

            if (wcdp_is_wcdp_payment_screen()) {
                //make sure we are triggering this only when the partial payment is edited to avoid loop

                $parent = wc_get_order($order->get_parent_id());

                if (!$parent) return;

                if ($order->get_meta('_wc_deposits_payment_type', true) === 'deposit') {
                    $parent->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                }
                $parent->save();
                $parent->payment_complete();
            }

        } else {
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);


            if ($order_has_deposit === 'yes') {
                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (is_array($payment_schedule)) {

                    //suppress partial payment and deposit payment complete emails in this process
                    add_filter('woocommerce_email_enabled_customer_deposit_partially_paid', '__return_false', 99);
                    add_filter('woocommerce_email_enabled_customer_partially_paid', '__return_false', 99);
                    add_filter('woocommerce_email_enabled_partial_payment', '__return_false', 99);
                    foreach ($payment_schedule as $payment) {


                        $payment_order = wc_get_order($payment['id']);

                        if ($payment_order && $payment_order->needs_payment()) {
                            $payment_order->payment_complete();
                            $payment_order->save();
                        }
                    }

                    //remove the email suppression
                    remove_filter('woocommerce_email_enabled_customer_deposit_partially_paid', '__return_false', 99);
                    remove_filter('woocommerce_email_enabled_customer_partially_paid', '__return_false', 99);
                    remove_filter('woocommerce_email_enabled_partial_payment', '__return_false', 99);
                }
                $order->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                $order->update_meta_data('_wc_deposits_second_payment_paid', 'yes');

                $order->save();

            }
        }

    }


    /**
     * @brief returns the proper status for order completion
     * @param $new_status
     * @param $order_id
     * @return string
     */
    public
    function payment_complete_order_status($new_status, $order_id)
    {

        $order = wc_get_order($order_id);

        if ($order) {

            //in case the order status for some reason end up as processing
            if ($order->get_type() === 'wcdp_payment' && $new_status === 'processing') return 'completed';

            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

            if ($order_has_deposit === 'yes') {
                //check if all payments are done before allowing default transition
                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return $new_status;
                $all_payments_made = true;

                foreach ($payment_schedule as $payment) {

                    // A scheduled installment with no order yet is, by definition, not paid.
                    if (empty($payment['id'])) {
                        $all_payments_made = false;
                        break;
                    }

                    $payment_order = wc_get_order($payment['id']);

                    if ($payment_order && ($payment_order->needs_payment() && $payment_order->get_meta('_wcdp_payment_complete') !== 'yes')) {
                        $all_payments_made = false;
                        break;
                    }
                }


                if (!$all_payments_made) {
                    $new_status = wcdp_partial_payment_complete_order_status();
                } else {
                    //all payments made status — land on the custom "Fully Paid" status
                    //by default (merchant can override via the setting).
                    $status = get_option('wc_deposits_order_fully_paid_status', 'fully-paid');
                    if (empty($status)) {
                        $status = 'fully-paid';
                    }
                    $new_status = apply_filters('wc_deposits_order_fully_paid_status', $status, $order_id);
                    //only save when the flag actually changes: WooCommerce calls this filter just to
                    //read the paid status (maybe_set_date_paid), and an unconditional save bumps the
                    //order's modified date, which loops the HPOS sync forever on that order.
                    if ($order->get_meta('_wc_deposits_second_payment_paid', true) !== 'yes') {
                        $order->update_meta_data('_wc_deposits_second_payment_paid', 'yes'); //BACKWARD COMPATIBILITY
                        $order->save();
                    }
                }
            }
        }
        return $new_status;
    }


    /**
     * @brief handle stock reduction on payment completion
     * @param $reduce
     * @param $order_id
     * @return bool
     */
    public
    function payment_complete_reduce_order_stock($reduce, $order_id)
    {
        $order = wc_get_order($order_id);

        // Bail without touching $reduce if the order can't be loaded — dereferencing
        // false here would fatal and abort the gateway's payment-complete flow.
        if (!$order) return $reduce;

        if ($order->get_type() === 'wcdp_payment') return false;


        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';

        if ($order_has_deposit) {


            $status = $order->get_status();
            $reduce_on = get_option('wc_deposits_reduce_stock', 'deposit');
            $valid_statuses = wcdp_valid_parent_statuses_for_partial_payment();

            if (in_array($status, $valid_statuses) && $reduce_on === 'full') {
                $reduce = false;
            } elseif ($status === 'processing' && $reduce_on === 'deposit') {
                $reduce = false;
            }
        }

        return $reduce;
    }


    /**
     * @param $editable
     * @param $order
     * @return bool
     */
    public
    function order_is_editable($editable, $order)
    {

        if ($order->has_status('partially-paid')) {
            $allow_edit = get_option('wc_deposits_partially_paid_orders_editable', 'yes') === 'yes';

            if ($allow_edit) {
                $editable = true;

            } else {

                $editable = false;
            }
        }
        return $editable;
    }


    /**
     * @param $statuses
     * @param $order
     * @return array
     */
    public
    function valid_order_statuses_for_payment_complete($statuses, $order)
    {


        if ($order->get_type() !== 'wcdp_payment' && get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {
            $statuses[] = 'partially-paid';
        }
        return $statuses;
    }

    /**
     * @brief Add the new 'Deposit paid' status to orders
     *
     * @return array
     */
    public
    function order_statuses($order_statuses)
    {
        $new_statuses = array();
        // Place the new statuses after 'Pending payment'
        foreach ($order_statuses as $key => $value) {
            $new_statuses[$key] = $value;
            if ($key === 'wc-pending') {
                $new_statuses['wc-partially-paid'] = esc_html__('Partially Paid', 'woocommerce-deposits');
                $new_statuses['wc-fully-paid'] = esc_html__('Fully Paid', 'woocommerce-deposits');
            }
        }
        return $new_statuses;
    }

    /**
     * @brief include the custom "Fully Paid" status in WooCommerce's paid / reporting
     *        status lists so is_paid(), revenue reporting and analytics treat a
     *        fully-paid deposit order the same as a completed one.
     * @param array $statuses
     * @return array
     */
    public
    function add_fully_paid_to_paid_statuses($statuses)
    {
        if (is_array($statuses) && !in_array('fully-paid', $statuses, true)) {
            $statuses[] = 'fully-paid';
        }
        return $statuses;
    }

    /**
     * @brief Remaining balance for a deposit parent order, or null if the order is
     *        not a deposit order. Parent holds the FULL total; amount paid is the sum
     *        of paid child payment orders, so balance = total - paid.
     * @param \WC_Order $order
     * @return float|null
     */
    public function get_deposit_order_balance($order)
    {
        if (!$order instanceof \WC_Order) {
            return null;
        }
        if ($order->get_meta('_wc_deposits_order_has_deposit', true) !== 'yes') {
            return null;
        }
        $schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
        if (!is_array($schedule) || empty($schedule)) {
            return null;
        }
        // Balance = sum of UNPAID installment orders, so a fully-paid order nets to $0
        // even if child totals don't reconcile exactly with the parent total.
        $balance = 0.0;
        foreach ($schedule as $payment) {
            if (empty($payment['id'])) {
                continue;
            }
            $payment_order = wc_get_order($payment['id']);
            if ($payment_order && !$payment_order->is_paid()) {
                $balance += (float) $payment_order->get_total();
            }
        }
        return $balance;
    }

    /**
     * @brief Add a "Balance" column to the My Account orders table (before actions).
     * @param array $columns
     * @return array
     */
    public function account_orders_balance_column($columns)
    {
        if (!is_array($columns)) {
            return $columns;
        }
        $new = array();
        foreach ($columns as $key => $label) {
            if ($key === 'order-actions') {
                $new['wcdp_balance'] = esc_html__('Balance', 'woocommerce-deposits');
            }
            $new[$key] = $label;
        }
        if (!isset($new['wcdp_balance'])) {
            $new['wcdp_balance'] = esc_html__('Balance', 'woocommerce-deposits');
        }
        return $new;
    }

    /**
     * @brief Render the My Account orders "Balance" column value.
     * @param \WC_Order $order
     * @return void
     */
    public function render_account_orders_balance_column($order)
    {
        $balance = $this->get_deposit_order_balance($order);
        if ($balance === null) {
            echo '&mdash;';
            return;
        }
        if ($balance <= 0) {
            echo esc_html__('Paid in full', 'woocommerce-deposits');
            return;
        }
        echo wp_kses_post(wc_price($balance, array('currency' => $order->get_currency())));
    }

    /**
     * @brief adds the status partially-paid to woocommerce
     * @param $has_status
     * @param $order
     * @param $status
     * @return bool
     */
    public
    function order_has_status($has_status, $order, $status)
    {
        if ($order->get_status() === 'partially-paid') {
            if (is_array($status)) {
                if (in_array('pending', $status)) {
                    $has_status = true;
                }
            } else {
                if ($status === 'pending') {
                    $has_status = true;
                }
            }
        }
        return $has_status;
    }

    /**
     * @brief adds deposit values to order item meta from cart item meta
     * @param $item_id
     * @param $item
     * @param $order_id
     * @throws \Exception
     */
    public
    function add_order_item_meta($item_id, $item)
    {

        if (is_array($item) && isset($item['deposit'])) {
            try {
                wc_add_order_item_meta($item_id, '_wc_deposit_meta', $item['deposit']);
            } catch (\Exception $e) {
                print_r(new WP_Error('error', $e->getMessage()));
            }
        }
    }

    /**
     * Record whether the email being rendered is plain text.
     *
     * @param \WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function capture_email_is_plain_text($order, $sent_to_admin = false, $plain_text = false)
    {
        $this->email_is_plain_text = (bool) $plain_text;
    }

    /**
     * @brief handles the display of order item totals in pay for order , my account  and email templates
     * @param $total_rows
     * @param $order
     * @return mixed
     */
    public

    function get_order_item_totals($total_rows, $order)
    {

        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';


        if ($order_has_deposit)  :

            $to_pay_text = esc_html__(get_option('wc_deposits_to_pay_text'), 'woocommerce-deposits');
            $deposit_amount_text = esc_html__(get_option('wc_deposits_deposit_amount_text'), 'woocommerce-deposits');
            $future_payment_amount_text = esc_html__(get_option('wc_deposits_second_payment_text'), 'woocommerce-deposits');

            if (empty($to_pay_text)) {
                $to_pay_text = esc_html__('To Pay', 'woocommerce-deposits');
            }


            if (empty($deposit_amount_text)) {
                $deposit_amount_text = esc_html__('Deposit Amount', 'woocommerce-deposits');
            }
            if (empty($future_payment_amount_text)) {
                $future_payment_amount_text = esc_html__('Future Payments', 'woocommerce-deposits');
            }

            $to_pay_text = stripslashes($to_pay_text);
            $deposit_amount_text = stripslashes($deposit_amount_text);
            $future_payment_amount_text = stripslashes($future_payment_amount_text);
            $status = $order->get_status();
            $deposit_paid = $order->get_meta('_wc_deposits_deposit_paid', true);
            $second_payment_paid = $order->get_meta('_wc_deposits_second_payment_paid', true);

            $payments = wcdp_get_order_partial_payments($order->get_id());
            $deposit_amount = 0.0;
            $second_payment = 0.0;

            foreach ($payments as $payment) {
                if ($payment->get_meta('_wc_deposits_payment_type', true) === 'deposit') {
                    $deposit_amount += $payment->get_total() - $payment->get_total_refunded();
                } else {
                    $second_payment += $payment->get_total() - $payment->get_total_refunded();
                }
            }


            $received_slug = get_option('woocommerce_checkout_order_received_endpoint', 'order-received');
            $pay_slug = get_option('woocommerce_checkout_order_pay_endpoint', 'order-pay');

            $is_checkout = (get_query_var($received_slug) === '' && is_checkout());
            $valid_statuses = wcdp_valid_parent_statuses_for_partial_payment();

            $is_paying_remaining = !!get_query_var($pay_slug) && in_array($status, $valid_statuses);
            $is_email = did_action('woocommerce_email_order_details') > 0;


            if (!$is_checkout || $is_email) {

                $total_rows['deposit_amount'] = array(
                    'label' => $deposit_amount_text,
                    'value' => wc_price($deposit_amount, array('currency' => $order->get_currency()))
                );

                $total_rows['second_payment'] = array(
                    'label' => $future_payment_amount_text,
                    'value' => wc_price($second_payment, array('currency' => $order->get_currency()))
                );


            }


            if ($is_checkout && !$is_paying_remaining && !$is_email) {

                $to_pay = false;
                if ($deposit_paid !== 'yes') {
                    $to_pay = $deposit_amount;
                } elseif ($deposit_paid === 'yes' && $second_payment_paid !== 'yes') {
                    $to_pay = $second_payment;
                }
                if ($to_pay) {
                    $total_rows['paid_today'] = array(
                        'label' => $to_pay_text,
                        'value' => wc_price($to_pay, array('currency' => $order->get_currency()))
                    );
                }

            }

            if ($is_checkout && $is_paying_remaining && !$is_email) {

                $partial_payment_id = absint(get_query_var($pay_slug));
                $partial_payment = wc_get_order($partial_payment_id);

                if ($partial_payment) {
                    $total_rows['paid_today'] = array(
                        'label' => $to_pay_text,
                        'value' => wc_price($partial_payment->get_total(), array('currency' => $order->get_currency()))
                    );
                }
            }

            // Restate "Total" the way the cart and checkout do — what was paid now,
            // "(of {order total})", with the outstanding balance underneath.
            //
            // Applied on the thank-you page and in every deposit email (customer AND
            // admin). NOT on My Account → View order, where the plain order total plus
            // the separate Deposit / Balance rows are still wanted.
            //
            // Plain-text emails are excluded on purpose. This value is HTML, and with the
            // email_improvements feature off WooCommerce runs it through wp_kses_post,
            // which keeps <strong>/<span> — the tags would show up as literal text in the
            // message. Plain-text emails therefore keep the untouched rows below.
            $is_thank_you = ! $is_email && get_query_var($received_slug) !== '' && function_exists('is_order_received_page') && is_order_received_page();
            $restate_total = $is_thank_you || ($is_email && ! $this->email_is_plain_text);

            if ($restate_total && isset($total_rows['order_total']) && $deposit_amount > 0 && $second_payment > 0) {

                // Show the payment the customer just completed, and what is genuinely
                // left. $deposit_amount / $second_payment are summed from the payment
                // orders WITHOUT regard to payment status, so $second_payment still reads
                // $3,545 after the balance is settled — the paid flags are the only
                // reliable state here. Without this, the balance-payment thank-you page
                // claims the balance is still due on a fully-paid order.
                $balance_settled = ($second_payment_paid === 'yes');
                $paid_now  = $balance_settled ? $second_payment : $deposit_amount;
                $still_due = $balance_settled ? 0.0 : $second_payment;

                $currency = array('currency' => $order->get_currency());
                $due_text = apply_filters('wc_deposits_balance_due_text', __('due at pickup or delivery', 'woocommerce-deposits'));

                // Reads like WC_Deposits_Cart::deposit_order_total_html(), with one
                // difference: the balance goes on its own line via <br> rather than
                // display:block on the span. Outlook's Word engine ignores display on
                // inline elements, so the cart's markup would run the two lines together
                // in email. <br> renders the same everywhere.
                $value  = '<strong>' . wc_price($paid_now, $currency) . '</strong>';
                $value .= ' <span class="wc-deposits-of-total" style="font-weight:400;opacity:0.75;">'
                    . sprintf(esc_html__('(of %s)', 'woocommerce-deposits'), wc_price($order->get_total(), $currency))
                    . '</span>';
                $value .= '<br><span class="wc-deposits-balance-note" style="font-weight:400;font-size:0.85em;opacity:0.75;">'
                    . wc_price($still_due, $currency) . ' ' . esc_html($due_text)
                    . '</span>';

                $total_rows['order_total']['value'] = $value;

                // The rebuilt Total now states both figures, so the separate Deposit /
                // Balance rows would just repeat it. Dropped here — inside the branch that
                // actually rewrote the Total — so they survive anywhere the rewrite didn't
                // happen (emails, My Account → View order, or a zero deposit/balance).
                unset($total_rows['deposit_amount'], $total_rows['second_payment']);
            }
        endif;
        return $total_rows;
    }


    /**
     * @brief handles formatted subtotal display for orders with deposit
     * @param $subtotal
     * @param $item
     * @param $order
     * @return string
     */
    public
    function order_formatted_line_subtotal($subtotal, $item, $order)
    {

        if (did_action('woocommerce_email_order_details')) return $subtotal;


        if ($order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes') {

            $product = $item->get_product();
            if (!$product) return $subtotal;
            if ($product->get_type() === 'bundle' || isset($item['_bundled_by'])) return $subtotal;

            if ($product && isset($item['wc_deposit_meta'])) {
                $deposit_meta = maybe_unserialize($item['wc_deposit_meta']);
            } else {
                return $subtotal;
            }

            if (is_array($deposit_meta) && isset($deposit_meta['enable']) && $deposit_meta['enable'] === 'yes') {
                $tax = get_option('wc_deposits_tax_display', 'no') === 'yes' ? floatval($item['line_tax']) : 0;

                if (wc_prices_include_tax()) {

                    $deposit = $deposit_meta['deposit'];

                } else {
                    $deposit = $deposit_meta['deposit'] + $tax;

                }

                return $subtotal . '<br/>(' .
                    wc_price($deposit, array('currency' => $order->get_currency())) . ' ' . esc_html__('Deposit', 'woocommerce-deposits') . ')';
            } else {
                return $subtotal;
            }
        } else {
            return $subtotal;
        }
    }


    function create_order($order_id, $checkout)
    {


        //return if there is no deposit in cart
        if (!isset(WC()->cart->deposit_info['deposit_enabled']) || WC()->cart->deposit_info['deposit_enabled'] !== true) {
            return $order_id;
        }

        $data = $checkout->get_posted_data();


        try {
            $order_id = absint(WC()->session->get('order_awaiting_payment'));
            $cart_hash = WC()->cart->get_cart_hash();
            

            $order = $order_id ? wc_get_order($order_id) : null;


            /**
             * If there is an order pending payment, we can resume it here so
             * long as it has not changed. If the order has changed, i.e.
             * different items or cost, create a new order. We use a hash to
             * detect changes which is based on cart items + order total.
             */
            if ($order && $order->has_cart_hash($cart_hash) && $order->has_status(array('pending', 'failed'))) {
                // Action for 3rd parties.
                do_action('woocommerce_resume_order', $order_id);

                // Remove all items - we will re-add them later.
                $order->remove_order_items();

                // Also remove the partial-payment child orders created on the previous
                // attempt — create_order_partial_payments_from_cart() builds a fresh set
                // below, and the old ones would otherwise be orphaned (no longer in the
                // schedule meta) and double-count in balance reporting. Paid children are
                // kept out of caution, though none should exist on a pending/failed parent.
                $stale_children = wc_get_orders(array(
                    'type'   => 'wcdp_payment',
                    'parent' => $order->get_id(),
                    'limit'  => -1,
                ));
                foreach ($stale_children as $stale_child) {
                    if ($stale_child->is_paid() || $stale_child->get_meta('_wcdp_payment_complete') === 'yes') {
                        continue;
                    }
                    $stale_child->delete(true);
                }
            } else {
                $order = new WC_Order();
            }


            $fields_prefix = array(
                'shipping' => true,
                'billing' => true,
            );

            $shipping_fields = array(
                'shipping_method' => true,
                'shipping_total' => true,
                'shipping_tax' => true,
            );

            foreach ($data as $key => $value) {


                if (is_callable(array($order, "set_{$key}"))) {
                    $order->{"set_{$key}"}($value);
                    // Store custom fields prefixed with shipping_ or billing_. This is for backwards compatibility with 2.6.x.
                } elseif (isset($fields_prefix[current(explode('_', $key))])) {
                    if (!isset($shipping_fields[$key])) {


                        $order->update_meta_data('_' . $key, $value);
                    }
                }
            }

            do_action('woocommerce_deposits_before_create_order', $order);


            $user_agent = wc_get_user_agent();
            /**
             * @var $checkout \WC_Checkout
             */


            $order->add_meta_data('_wc_deposits_order_has_deposit', 'yes', true);
            $order->set_created_via('checkout');
            $order->set_cart_hash($cart_hash);
            $order->set_customer_id(apply_filters('woocommerce_checkout_customer_id', get_current_user_id()));
            $order_vat_exempt = WC()->cart->get_customer()->get_is_vat_exempt() ? 'yes' : 'no';
            $order->add_meta_data('is_vat_exempt', $order_vat_exempt);
            $order->set_currency(get_woocommerce_currency());
            $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
            $order->set_customer_ip_address(WC_Geolocation::get_ip_address());
            $order->set_customer_user_agent($user_agent);
            $order->set_customer_note(isset($data['order_comments']) ? $data['order_comments'] : '');
            $order->set_payment_method('');
            $order->set_shipping_total(WC()->cart->get_shipping_total());
            $order->set_discount_total(WC()->cart->get_discount_total());
            $order->set_discount_tax(WC()->cart->get_discount_tax());
            $order->set_cart_tax(WC()->cart->get_cart_contents_tax() + WC()->cart->get_fee_tax());
            $order->set_shipping_tax(WC()->cart->get_shipping_tax());
            $order->set_total(WC()->cart->get_total('edit'));
            $checkout->create_order_line_items($order, WC()->cart);
            $checkout->create_order_fee_lines($order, WC()->cart);
            $checkout->create_order_shipping_lines($order, WC()->session->get('chosen_shipping_methods'), WC()->shipping()->get_packages());
            $checkout->create_order_tax_lines($order, WC()->cart);
            $checkout->create_order_coupon_lines($order, WC()->cart);


            do_action('woocommerce_deposits_after_create_order', $order);

            /**
             * Action hook to adjust order before save.
             *
             * @since 3.0.0
             */
            do_action('woocommerce_checkout_create_order', $order, $data);

            // Save the order.
            $order_id = $order->save();

            do_action('woocommerce_checkout_update_order_meta', $order_id, $data);
            do_action( 'woocommerce_checkout_order_created', $order );

            //create all payments
            $order->read_meta_data();
            $deposit_id = $this->create_order_partial_payments_from_cart($order,$data['payment_method']);

            return absint($deposit_id);

        } catch (\Exception $e) {
            return new WP_Error('checkout-error', $e->getMessage());
        }
    }

    function create_order_partial_payments_from_cart($order,$gateway){


        $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule');
       
        $deposit_id = null;
        $partial_payments_structure = apply_filters('wc_deposits_partial_payments_structure', get_option('wc_deposits_partial_payments_structure', 'single'), 'checkout');
        if ($partial_payments_structure !== 'single') {
            $order->add_meta_data('_wc_deposits_itemized_payments', 'yes');
        } else {
            $order->add_meta_data('_wc_deposits_itemized_payments', 'no');
        }
        foreach ($payment_schedule as $partial_key => $payment) {

            
            $partial_payment = new WCDP_Payment();

            

            $partial_payment->set_customer_id(apply_filters('woocommerce_checkout_customer_id', get_current_user_id()));

            if ($partial_payments_structure === 'single') {
                $amount = $payment['total'];
                //allow partial payments to be inserted only as a single fee without item details
                $name = esc_html__('Partial Payment for order %s', 'woocommerce-deposits');
                $partial_payment_name = apply_filters('wc_deposits_partial_payment_name', sprintf($name, $order->get_order_number()), $payment, $order->get_id());


                $item = new WC_Order_Item_Fee();
                $item->set_props(
                    array(
                        'total' => $amount
                    )
                );
                $item->set_name($partial_payment_name);
                $partial_payment->add_item($item);
                $partial_payment->set_total($amount);

            } else {
                $amount = $payment['total'];
                $partial_payment = Wc_Deposits_Admin_order::create_partial_payment_items($partial_payment, $order, $payment['details']);
                $partial_payment->save();
                $partial_payment->add_meta_data('_wc_deposits_partial_payment_itemized', 'yes');
                $partial_payment->set_total($amount);
            }

            $partial_payment->set_parent_id($order->get_id());
            $partial_payment->add_meta_data('is_vat_exempt', $order->get_meta('is_vat_exempt', true));
            $partial_payment->add_meta_data('_wc_deposits_payment_type', $payment['type']);

            if (isset($payment['timestamp']) && is_numeric($payment['timestamp'])) {
                $partial_payment->add_meta_data('_wc_deposits_partial_payment_date', $payment['timestamp']);
            }

            if (isset($payment['details'])) {
                $partial_payment->add_meta_data('_wc_deposits_partial_payment_details', $payment['details']);
            }

            $partial_payment->set_currency(get_woocommerce_currency());
            $partial_payment->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
            $partial_payment->set_customer_ip_address(WC_Geolocation::get_ip_address());
            $partial_payment->set_customer_user_agent($order->get_customer_user_agent());

            $partial_payment->save();


            $payment_schedule[$partial_key]['id'] = $partial_payment->get_id();
         
            //add wpml language for all child orders for wpml
            $wpml_lang = $order->get_meta('wpml_language', true);
            if (!empty($wpml_lang)) {
                $partial_payment->update_meta_data('wpml_language', $wpml_lang);
            }

            if ($payment['type'] === 'deposit') {
                //we need to save to generate id first
                $deposit_id = $partial_payment->get_id();

              
                if (isset($gateway) && !empty($gateway)) {

                   $partial_payment->set_payment_method($gateway);
               }

            }

            $partial_payment->save();
            do_action('wc_deposits_partial_payment_created', $partial_payment->get_id(), 'checkout');
        }


   
  
        // lets get the order again to update the schedule meta to avoid a bug in saving
        $order = wc_get_order($order->get_id());
        //update the schedule meta of parent order
        $order->update_meta_data('_wc_deposits_payment_schedule', $payment_schedule);
        $order->save();


        return $deposit_id;
    }


    function partial_payment_number($number, $order)
    {
        if (is_order_received_page() && did_action('woocommerce_before_thankyou') && !did_action('woocommerce_thankyou')) return $number;


        if ($order && $order->get_type() === 'wcdp_payment') {
            $parent = wc_get_order($order->get_parent_id());
            if ($parent) {
                $payment_schedule = $parent->get_meta('_wc_deposits_payment_schedule', true);
                $count = 0;
                $suffix = '-';
                if (!empty($payment_schedule) && is_array($payment_schedule)) {
                    foreach ($payment_schedule as $payment) {
                        $count++;
                        if (isset($payment['id']) && $payment['id'] == $order->get_id()) {
                            $suffix .= $count;
                            break;
                        }
                    }
                }


                $number = $parent->get_order_number() . $suffix;
            }
        }
        return $number;
    }

    static function get_order_balance_details($order)
    {


        $partial_payments = wcdp_get_order_partial_payments($order->get_id());
        $paid = 0.0;
        foreach ($partial_payments as $partial_payment) {
            if ($partial_payment->is_paid() || $partial_payment->get_meta('_wcdp_payment_complete') === 'yes') {
                $total = $partial_payment->get_total() - floatval($partial_payment->get_total_refunded());
                $paid += $total;
            }
        }

        $order_total = $order->get_total() - floatval($order->get_total_refunded());

        $order_total = round($order_total, wc_get_price_decimals());
        $paid = round($paid, wc_get_price_decimals());
        $unpaid = $order_total - $paid;

        return array('order_total ' => $order_total, 'paid' => $paid, 'unpaid' => $unpaid);
    }
}

