<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('WC_Deposits_Email_Customer_Fully_Paid')) :

    /**
     * Customer Fully Paid Email
     *
     * Sent to the customer when their order has been paid in full (deposit plus all
     * remaining installments). The plugin historically shipped only an admin
     * full-payment notification; this is its customer-facing counterpart.
     *
     * Triggered on the parent order's transition into the fully-paid target status.
     * By default that is the custom "fully-paid" status; the processing/completed
     * triggers are kept in case a merchant overrides the fully-paid target to a
     * native status.
     */
    class WC_Deposits_Email_Customer_Fully_Paid extends WC_Email
    {

        /**
         * Constructor
         */
        function __construct()
        {
            $this->id = 'customer_fully_paid';
            $this->title = esc_html__('Order Fully Paid', 'woocommerce-deposits');
            $this->customer_email = true;

            $this->description = esc_html__('Order notification sent to the customer once their order has been paid in full (deposit plus all remaining payments).', 'woocommerce-deposits');

            $this->template_html = 'emails/customer-order-fully-paid.php';
            $this->template_plain = 'emails/plain/customer-order-fully-paid.php';

            // Fire when the FINAL payment order is actually PAID, not when the parent's
            // status is changed. A parent can be moved to the fully-paid status manually
            // in wp-admin with no payment taken; keying off a child payment order's
            // completion means the email only sends when a real payment settles the
            // order. This also covers the in-person case: when staff record the balance
            // later by marking the payment order paid/completed in the backend, that
            // completion is what triggers the email.
            add_action('woocommerce_order_status_processing_notification', array($this, 'maybe_trigger'));
            add_action('woocommerce_order_status_completed_notification', array($this, 'maybe_trigger'));

            // Call parent constructor
            parent::__construct();

            $this->template_base = WC_DEPOSITS_TEMPLATE_PATH;
        }

        /**
         * Fire only when a child payment order's completion settles the whole order.
         *
         * The parent reaches its configured fully-paid status only once every
         * installment is paid, so testing the parent status here is true only on the
         * final payment — and never for a manual parent status change, which produces
         * no child payment transition to hook.
         *
         * @param int $child_id The wcdp_payment order ID whose status just changed.
         */
        public function maybe_trigger($child_id)
        {
            if (!$child_id) return;

            $child = wc_get_order($child_id);
            if (!$child || $child->get_type() !== 'wcdp_payment') return;

            $parent = wc_get_order($child->get_parent_id());
            if (!$parent) return;

            $fully_paid_status = get_option('wc_deposits_order_fully_paid_status', 'fully-paid');
            $fully_paid_status = $fully_paid_status ? preg_replace('/^wc-/', '', $fully_paid_status) : 'fully-paid';

            if ($parent->get_status() !== $fully_paid_status) return;

            $this->trigger($parent->get_id());
        }

        /**
         * Trigger the sending of this email.
         *
         * @param int $order_id The parent order ID.
         */
        public function trigger($order_id)
        {
            if (!$order_id) return;

            $order = wc_get_order($order_id);
            if (!$order) return;

            // The transition is on the parent shop_order; never fire for a child payment order.
            if ($order->get_type() === 'wcdp_payment') return;

            // Send once per order. A meta marker (rather than the old admin-save
            // did_action guard) still fires when staff record the final balance by
            // completing the child payment order in wp-admin — the business's normal
            // pay-at-pickup flow — while preventing duplicates if the parent is ever
            // bounced back and re-transitioned.
            if ($order->get_meta('_wc_deposits_customer_fully_paid_email_sent') === 'yes') return;

            $this->object = $order;

            $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
            $this->recipient = $this->object->get_billing_email();

            if (!$this->is_enabled() || !$this->get_recipient()) {
                return;
            }

            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());

            $order->update_meta_data('_wc_deposits_customer_fully_paid_email_sent', 'yes');
            $order->save();
        }

        /**
         * get_content_html function.
         *
         * @access public
         */
        function get_content_html()
        {
            return wc_get_template_html($this->template_html, array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'email_text' => $this->get_email_text(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ), '', $this->template_base);
        }

        /**
         * get_content_plain function.
         *
         * @access public
         */
        function get_content_plain()
        {
            return wc_get_template_html($this->template_plain, array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'email_text' => $this->get_email_text(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
            ), '', $this->template_base);
        }

        public function get_default_subject()
        {
            return esc_html__('Your {site_title} order is paid in full ({order_number})', 'woocommerce-deposits');
        }

        public function get_default_heading()
        {
            return esc_html__('Your order is paid in full', 'woocommerce-deposits');
        }

        public function get_default_email_text()
        {
            return esc_html__('Great news — your order {order_number} has been paid in full. Your order details are shown below for your reference:', 'woocommerce-deposits');
        }

        function get_email_text()
        {
            $text = $this->get_option('email_text', $this->get_default_email_text());
            return $this->format_string($text);
        }

        public function init_form_fields()
        {
            /* translators: %s: list of placeholders */
            $placeholder_text = sprintf(wp_kses(__('Available placeholders: %s', 'woocommerce'), array('code' => array())), '<code>' . esc_html(implode('</code>, <code>', array_keys($this->placeholders))) . '</code>');
            $this->form_fields = array(
                'enabled' => array(
                    'title' => esc_html__('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => esc_html__('Enable this email notification', 'woocommerce'),
                    'default' => 'yes',
                ),
                'subject' => array(
                    'title' => esc_html__('Subject', 'woocommerce'),
                    'type' => 'text',
                    'description' => $placeholder_text,
                    'desc_tip' => true,
                    'placeholder' => $this->get_default_subject(),
                    'default' => $this->get_default_subject(),
                ),
                'heading' => array(
                    'title' => esc_html__('Email heading', 'woocommerce'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => $placeholder_text,
                    'placeholder' => $this->get_default_heading(),
                    'default' => $this->get_default_heading(),
                ),
                'email_text' => array(
                    'title' => esc_html__('Email text', 'woocommerce'),
                    'placeholder' => $this->get_default_email_text(),
                    'default' => $this->get_default_email_text(),
                    'description' => esc_html__('Text to appear as email content message', 'woocommerce-deposits') . ' ' . $placeholder_text,
                    'css' => 'width:400px; height: 75px;',
                    'type' => 'textarea',
                    'desc_tip' => true,
                ),
                'additional_content' => array(
                    'title' => esc_html__('Additional content', 'woocommerce'),
                    'description' => esc_html__('Text to appear below the main email content.', 'woocommerce') . ' ' . $placeholder_text,
                    'placeholder' => esc_html__('N/A', 'woocommerce'),
                    'css' => 'width:400px; height: 75px;',
                    'type' => 'textarea',
                    'default' => $this->get_default_additional_content(),
                    'desc_tip' => true,
                ),
                'email_type' => array(
                    'title' => esc_html__('Email type', 'woocommerce'),
                    'type' => 'select',
                    'description' => esc_html__('Choose which format of email to send.', 'woocommerce'),
                    'default' => 'html',
                    'class' => 'email_type wc-enhanced-select',
                    'options' => $this->get_email_type_options(),
                    'desc_tip' => true,
                ),
            );
        }

    }

endif;

return new WC_Deposits_Email_Customer_Fully_Paid();
