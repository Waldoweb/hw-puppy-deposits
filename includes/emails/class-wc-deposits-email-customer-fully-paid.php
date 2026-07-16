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

            $partial_payment_status = wcdp_partial_payment_complete_order_status();

            // Triggers for this email — fires on the parent's transition into the
            // fully-paid target status (see class docblock).
            add_action('woocommerce_order_status_' . $partial_payment_status . '_to_fully-paid_notification', array($this, 'trigger'));
            add_action('woocommerce_order_status_' . $partial_payment_status . '_to_processing_notification', array($this, 'trigger'));
            add_action('woocommerce_order_status_' . $partial_payment_status . '_to_completed_notification', array($this, 'trigger'));

            // Call parent constructor
            parent::__construct();

            $this->template_base = WC_DEPOSITS_TEMPLATE_PATH;
        }

        /**
         * Trigger the sending of this email.
         *
         * @param int $order_id The parent order ID.
         */
        public function trigger($order_id)
        {
            if (did_action('woocommerce_process_shop_order_meta')) return; // don't fire when admin saves the order in the backend

            if (!$order_id) return;

            $order = wc_get_order($order_id);
            if (!$order) return;

            // The transition is on the parent shop_order; never fire for a child payment order.
            if ($order->get_type() === 'wcdp_payment') return;

            $this->object = $order;

            $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
            $this->recipient = $this->object->get_billing_email();

            if (!$this->is_enabled() || !$this->get_recipient()) {
                return;
            }

            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
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
