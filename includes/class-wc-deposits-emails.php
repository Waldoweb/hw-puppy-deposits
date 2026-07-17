<?php
/*Copyright: © 2026 Hostetler Web.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
namespace DepositWP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @brief Handles email notifications
 *
 * @since 1.3
 *
 */
class WC_Deposits_Emails
{
    private static $_instance = null;

    public $actions = array();

    /**
     * WC_Deposits_Emails constructor.
     */
    public function __construct()
    {

        $partial_payment_status = 'partially-paid'; //avoid a bug during activation
        if(function_exists('wcdp_partial_payment_complete_order_status')){
            $partial_payment_status = wcdp_partial_payment_complete_order_status();
        }

        $full_payment_status = get_option('wc_deposits_order_fully_paid_status', 'fully-paid');

        $email_actions = array(
            array(
                'from' => array('pending', 'on-hold', 'failed', 'draft'),
                'to' => array($partial_payment_status)
            ),
            array(
                'from' => array($partial_payment_status),
                'to' => array($full_payment_status,'processing', 'completed', 'on-hold')
            )
        );

        foreach ($email_actions as $action) {
            foreach ($action['from'] as $from) {
                foreach ($action['to'] as $to) {
                    $this->actions[] = 'woocommerce_order_status_' . $from . '_to_' . $to;
                }
            }
        }
        $this->actions[] = 'woocommerce_deposits_second_payment_reminder_email';
        $this->actions = array_unique($this->actions);

        add_filter('woocommerce_email_actions', array($this, 'email_actions'));
        add_action('woocommerce_email', array($this, 'register_hooks'));
        add_filter('woocommerce_email_classes', array($this, 'email_classes'));
        add_filter('woocommerce_purchase_note_order_statuses', array($this, 'purchase_note_order_statuses'), 10, 1);


        add_action('woocommerce_email_order_details', array($this, 'partial_payment_details'), 20, 4);
        add_action('woocommerce_email_order_details', array($this, 'original_order_summary'), 20, 4);


        /** DISABLE SPECIFIC EMAILS FOR THE WCDP_PAYMENT ORDER TYPE */

        add_filter('woocommerce_email_enabled_new_order', array($this, 'disable_wcdp_payment_emails'), PHP_INT_MAX, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', array($this, 'disable_wcdp_payment_emails'), PHP_INT_MAX, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', array($this, 'disable_wcdp_payment_emails'), PHP_INT_MAX, 2);
        add_filter('woocommerce_email_enabled_customer_completed_order', array($this, 'disable_wcdp_payment_emails'), PHP_INT_MAX, 2);
    }

    public static function instance()
    {

        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;

    }

    function disable_wcdp_payment_emails($enabled, $order)
    {

        if(!is_object($order)) return $enabled;
        // fix for when an order is constructed using new Wc_Order instead of wc_get_order() , in this case the returned order type is 'shop_order'
        $fetched = wc_get_order($order->get_id());
        if ($fetched) {
            $order = $fetched;
        }

        //double check in case a new WC_order constructor is used by another plugin
        if ($order->get_type() === 'wcdp_payment' || $order->meta_exists('_wc_deposits_payment_type')){
            $enabled = false;
        }

       return $enabled;
    }


    function original_order_summary($order, $sent_to_admin = false, $plain_text = false, $email = '')
    {

        if ($order->get_type() !== 'wcdp_payment') return;
        $parent = wc_get_order($order->get_parent_id());
        if (!$parent) return; // orphaned payment order — nothing to summarise

        ?> <p> <?php echo esc_html__('Below is a summary of original order', 'woocommerce-deposits') ?>
        <strong><?php echo $parent->get_order_number(); ?> </strong></p> <?php
        if ( $plain_text ) {
            wc_get_template(
                'emails/plain/email-order-details.php',
                array(
                    'order'         => $parent,
                    'sent_to_admin' => $sent_to_admin,
                    'plain_text'    => $plain_text,
                    'email'         => $email,
                )
            );
        } else {
            wc_get_template(
                'emails/email-order-details.php',
                array(
                    'order'         => $parent,
                    'sent_to_admin' => $sent_to_admin,
                    'plain_text'    => $plain_text,
                    'email'         => $email,
                )
            );
        }
    }

    /**
     * Show the original order details table
     *
     * @param \WC_Order $order Order instance.
     * @param bool $sent_to_admin If should sent to admin.
     * @param bool $plain_text If is plain text email.
     * @param string $email Email address.
     */
    public function partial_payment_details($order, $sent_to_admin = false, $plain_text = false, $email = '')
    {

        if(!apply_filters('wc_deposits_email_show_partial_payments_summary',true,$order,$email,$sent_to_admin)) return;
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if ($order_has_deposit !== 'yes') return;

        $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
        if (empty($payment_schedule)) return;

        if ($plain_text ) {
            wc_get_template(
                'emails/plain/wc-deposits-email-partial-payments-summary.php', array(
                'order' => $order,
                'sent_to_admin' => $sent_to_admin,
                'plain_text' => $plain_text,
                'email' => $email,
                'schedule' => $payment_schedule
            ),
                '',
                WC_DEPOSITS_TEMPLATE_PATH
            );
        } else {
            wc_get_template(
                'emails/wc-deposits-email-partial-payments-summary.php', array(
                'order' => $order,
                'sent_to_admin' => $sent_to_admin,
                'plain_text' => $plain_text,
                'email' => $email,
                'schedule' => $payment_schedule
            ),
                '',
                WC_DEPOSITS_TEMPLATE_PATH
            );
        }
    }


    /**
     * @brief Merge this class actions with Woocommerce Email actions
     * @param $actions
     * @return array
     */
    public function email_actions($actions)
    {


        return array_unique(array_merge($actions, $this->actions));
    }

    /**
     * @brief Hook our custom order status to all relevant existing email classes
     *
     * @return void
     * @since 1.3
     *
     */
    public function register_hooks($wc_emails)
    {
        $partial_payment_status = wcdp_partial_payment_complete_order_status();
        $class_actions = array(
            'WC_Email_New_Order' => array(
                array(
                    'from' => array('pending', 'failed', 'draft'),
                    'to' => array($partial_payment_status)
                ),
            ),
            'WC_Email_Customer_Processing_Order' => array(
                array(
                    'from' => array($partial_payment_status),
                    'to' => array('processing')
                ),
            ), 'WC_Email_Customer_On_Hold_Order' => array(
                /**
                 * @since 2.5.11
                 */
                array(
                    'from' => array($partial_payment_status),
                    'to' => array( 'on-hold')
                ),
            ),
        );

        foreach ($wc_emails->emails as $class => $instance) {
            if (isset($class_actions[$class])) {
                foreach ($class_actions[$class] as $actions) {
                    foreach ($actions['from'] as $from) {
                        foreach ($actions['to'] as $to) {
                            add_action('woocommerce_order_status_' . $from . '_to_' . $to . '_notification', array($instance, 'trigger'));
                        }
                    }
                }
            }
        }

    }


    /**
     * @brief add partially-paid to purchase note order status
     * @param $statuses
     * @return array
     */
    function purchase_note_order_statuses($statuses)
    {

        $statuses[] = 'partially-paid';

        return $statuses;
    }



    /**
     * @brief add woocommerce deposits email classes to woocommerce
     * @param $emails
     * @return mixed
     */
    public function email_classes($emails)
    {
        $emails['WC_Deposits_Email_Partial_Payment'] = include('emails/class-wc-deposits-email-partial-payment.php');
        $emails['WC_Deposits_Email_Full_Payment'] = include('emails/class-wc-deposits-email-full-payment.php');
        $emails['WC_Deposits_Email_Customer_Deposit_Paid'] = include('emails/class-wc-deposits-email-customer-deposit-paid.php');
        $emails['WC_Deposits_Email_Customer_Partial_Payment_Paid'] = include('emails/class-wc-deposits-email-customer-partially-paid.php');
        $emails['WC_Deposits_Email_Customer_Fully_Paid'] = include('emails/class-wc-deposits-email-customer-fully-paid.php');
        $emails['WC_Deposits_Email_Customer_Remaining_Reminder'] = include('emails/class-wc-deposits-email-customer-remaining-reminder.php');
        return $emails;
    }
}
