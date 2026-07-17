<?php
/*Copyright: © 2026 Hostetler Web.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace DepositWP;

use Automattic\WooCommerce\Utilities\OrderUtil;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Deposits_Reminders
{
    function __construct()
    {

        //reminder for datepicker setting
        add_action('wc_deposits_job_scheduler', array($this, 'second_payment_datepicker_reminder'));

        //reminder for after X days setting
        $second_payment_reminder = get_option('wc_deposits_enable_second_payment_reminder');
        $partial_payment_reminder = get_option('wc_deposits_enable_partial_payment_reminder');

        if ($second_payment_reminder === 'yes') {

            add_action('wc_deposits_job_scheduler', array($this, 'second_payment_reminder'));

        }

        if ($partial_payment_reminder === 'yes') {
            add_action('wc_deposits_job_scheduler', array($this, 'payment_plan_partial_payment_reminder'));
        }

        /** PRODUCT BASED REMINDERS */


        //the core reminder cron hook
        add_action('wc_deposits_job_scheduler', array($this, 'second_payment_product_based_reminder'));


    }

    function payment_plan_partial_payment_reminder()
    {

        $reminder_days = get_option('wc_deposits_partial_payment_reminder_x_days_before_due_date');
        $date = date("Y-m-d", current_time('timestamp'));
        $target_due_date = strtotime("$date +{$reminder_days} days");


        if (empty($reminder_days)) return;

        $args = array(
            'status' => array('wc-pending', 'wc-failed'), // Specify the order status\
            'type' => 'wcdp_payment',
            'limit' => -1, // Limit of orders
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_wc_deposits_payment_type',
                    'value' => 'partial_payment',
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_wc_deposits_partial_payment_date',
                    'value' => array(strtotime($date), $target_due_date),
                    'compare' => 'EXISTS',
                ),
                array(
                    'key' => '_wc_deposits_partial_payment_date',
                    'value' => array(strtotime($date), $target_due_date),
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ),
                array(
                    'key' => '_wc_deposits_partial_payment_reminder_email_sent',
                    'value' => 'yes',
                    'compare' => 'NOT LIKE'
                ),
            )
        );


// Query for all relevant orders
        $orders = wc_get_orders($args);
        foreach ($orders as $order) {
            if (!$order->meta_exists('_wc_deposits_partial_payment_date') || $order->get_meta('_wc_deposits_partial_payment_reminder_email_sent') === 'yes') continue;

            $order->update_meta_data('_wc_deposits_partial_payment_reminder_email_sent', 'yes');
            $order->update_meta_data('_wc_deposits_partial_payment_reminder_email_sent_time', current_time('timestamp'));
            $order->save();
            do_action('woocommerce_deposits_second_payment_reminder_email', $order->get_parent_id(), true, $order->get_id());
        }

    }


    /**
     * @brief handle second payment reminder email triggered by product datepicker setting
     */
    function second_payment_product_based_reminder()
    {

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_wc_deposits_pbr_reminder_date',
            'meta_value' => date('d-m-Y'),
            'meta_compare' => 'LIKE',
        );

        $products = wc_get_products($args);

        if (empty($products)) return;

        foreach ($products as $product) {

            $order_ids = $this->retrieve_orders_ids_from_a_product_id(intval($product->get_id()));
            $order_ids = array_map('intval', $order_ids);

            if (!empty($order_ids)) {
                $orders = wc_get_orders(array(
                    'include'    => $order_ids,
                    'limit'      => count($order_ids)
                ));                foreach ($orders as $order) {

                    if ($order && $order->needs_payment()) {
                        $reminder_already_sent = $order->get_meta('_wc_deposits_second_payment_reminder_email_pbr_sent', true);

                        if ($reminder_already_sent !== 'yes') {

                            do_action('woocommerce_deposits_second_payment_reminder_email', $order->get_id());

                            $order->update_meta_data('_wc_deposits_second_payment_reminder_email_pbr_sent', 'yes');
                            $order->update_meta_data('_wc_deposits_second_payment_reminder_email_pbr_time', current_time('timestamp'));
                            $order->save_meta_data();
                            $order->save();
                        }

                    }

                }
            }
        }
    }

    /**
     * @brief handle second payment reminder email triggered by datepicker setting
     */
    function second_payment_datepicker_reminder()
    {

        $reminder_date = get_option('wc_deposits_reminder_datepicker');

        if (date('Y-m-d', current_time('timestamp')) == date('Y-m-d', strtotime($reminder_date))) {

            $args = array(
                'status' => 'partially-paid', // Using 'status' instead of 'post_status'
                'limit' => -1, // 'posts_per_page' is replaced with 'limit'
                // Add additional arguments as needed
            );

            // Using wc_get_orders to query orders
            $partially_paid_orders = wc_get_orders($args);

            $reminder_day = date('Y-m-d', strtotime($reminder_date));

            foreach ($partially_paid_orders as $order) {
                $order_id = $order->get_id();

                // Guard against a duplicate/retried cron run re-emailing every
                // partially-paid order: send once per configured reminder date.
                if ($order->get_meta('_wc_deposits_datepicker_reminder_sent') === $reminder_day) {
                    continue;
                }

                do_action('woocommerce_deposits_second_payment_reminder_email', $order_id);

                $order->update_meta_data('_wc_deposits_datepicker_reminder_sent', $reminder_day);
                $order->save();
            }

        }


    }

    /**
     * @brief handles second payment reminder email trigger
     */
    public function second_payment_reminder()
    {


        $reminder_duration_option = get_option('wc_deposits_second_payment_reminder_duration');

        // Not configured — never send.
        if ('' === trim((string) $reminder_duration_option)) {
            return;
        }

        // Define your meta query arguments
        $args = array(
            'status' => 'partially-paid', // Order status
            'limit' => -1, // Limiting number of results
            'type' => 'shop_order', // Post type
             'meta_query' => array(
            'relation' => 'OR', // Ensuring either condition is met
            array(
                'key' => '_wc_deposits_second_payment_reminder_email_sent',
                'compare' => 'NOT EXISTS' // Meta key doesn't exist
            ),
            array(
                'key' => '_wc_deposits_second_payment_reminder_email_sent',
                'value' => 'no',
                'compare' => '=' // Meta value is explicitly "no"
            ),
        ),
        );
        
        
 

        // Get orders based on the meta query
        $partially_paid_orders = wc_get_orders($args);      
           
        
        // Loop through the orders
        foreach ($partially_paid_orders as $order) {
            // Do something with each order
            $order_id = $order->get_id();
            if ($order->get_meta('_wc_deposits_second_payment_reminder_email_sent') === 'yes') continue;
            $deposit_payment_date = $order->get_meta('_wc_deposits_deposit_payment_time', true);

            if ($deposit_payment_date > 0) {
                $now = strtotime(date('Y-m-d', current_time('timestamp')));
                $duration_since_deposit_paid = round($now - intval($deposit_payment_date));

                //change the date to days
                $duration_since_deposit_paid = round($duration_since_deposit_paid / (60 * 60 * 24));
      

                // Send once the configured number of days has ELAPSED since the deposit
                // was paid. (This was previously `<=`, which sent the reminder on the
                // first cron run after payment and never for orders already older than
                // the window.) The `_email_sent` marker above ensures it fires once.
                if (intval($duration_since_deposit_paid) >= intval($reminder_duration_option)) {

                    do_action('woocommerce_deposits_second_payment_reminder_email', $order_id);
                    $order->update_meta_data('_wc_deposits_second_payment_reminder_email_sent', 'yes');
                    $order->update_meta_data('_wc_deposits_second_payment_reminder_email_time', current_time('timestamp'));
                    $order->save_meta_data();
                    $order->save();
                }
            }
        }
    }

    function retrieve_orders_ids_from_a_product_id($product_id)
    {
        global $wpdb;

        $orders_statuses = "'wc-partially-paid'";


        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $id_column = 'id';
            $status_column = 'status';
        } else {
            $orders_table = $wpdb->prefix . 'posts';
            $id_column = 'ID';
            $status_column = 'post_status';
        }

        $orders_ids = $wpdb->get_col("
        SELECT DISTINCT woi.order_id
        FROM {$wpdb->prefix}woocommerce_order_itemmeta as woim, 
             {$wpdb->prefix}woocommerce_order_items as woi, 
             $orders_table as o
        WHERE  woi.order_item_id = woim.order_item_id
        AND woi.order_id = o.$id_column
        AND o.$status_column IN ( $orders_statuses )
        AND woim.meta_key LIKE '_product_id'
        AND woim.meta_value LIKE '$product_id'
        ORDER BY woi.order_item_id DESC"
        );
        // Return an array of Orders IDs for the given product ID
        return $orders_ids;
    }

}