<?php

if( ! defined( 'ABSPATH' ) ){
	exit; // Exit if accessed directly
}

if( ! class_exists( 'WC_Deposits_Email_Full_Payment' ) ):
	
	/**
	 * @brief Full Payment Email
	 *
	 * An email sent to the admin when an order is fully paid for.
	 *
	 */
	class WC_Deposits_Email_Full_Payment extends WC_Email{
		
		/**
		 * Constructor
		 */
		function __construct(){
			
			$this->id = 'full_payment';
			$this->title = esc_html__( 'Full Payment' , 'woocommerce-deposits' );
			$this->description = esc_html__( 'Full payment emails are sent when an order is fully paid.' , 'woocommerce-deposits' );
			
			$this->heading = esc_html__( 'Order fully paid' , 'woocommerce-deposits' );
			$this->subject = esc_html__( '[{site_title}] Order fully paid ({order_number}) - {order_date}' , 'woocommerce-deposits' );
			
			$this->template_html = 'emails/admin-order-fully-paid.php';
			$this->template_plain = 'emails/plain/admin-order-fully-paid.php';
			// Fire when the FINAL payment order is actually PAID, not when the parent's
			// status is changed. A parent can be moved to the fully-paid status manually
			// in wp-admin with no payment taken; keying off a child payment order's
			// completion means the notice only sends when a real payment settles the
			// order — including an in-person balance recorded later by marking the
			// payment order paid/completed in the backend.
			add_action( 'woocommerce_order_status_processing_notification', array( $this, 'maybe_trigger' ) );
			add_action( 'woocommerce_order_status_completed_notification', array( $this, 'maybe_trigger' ) );

			// Call parent constructor
			parent::__construct();

			$this->template_base = WC_DEPOSITS_TEMPLATE_PATH;

			// Other settings
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );


		}

		/**
		 * Fire only when a child payment order's completion settles the whole order.
		 * See the customer fully-paid email for the rationale; this is the admin twin.
		 *
		 * @param int $child_id The wcdp_payment order ID whose status just changed.
		 */
		public function maybe_trigger( $child_id ){
			if ( ! $child_id ) return;

			$child = wc_get_order( $child_id );
			if ( ! $child || $child->get_type() !== 'wcdp_payment' ) return;

			$parent = wc_get_order( $child->get_parent_id() );
			if ( ! $parent ) return;

			$fully_paid_status = get_option( 'wc_deposits_order_fully_paid_status', 'fully-paid' );
			$fully_paid_status = $fully_paid_status ? preg_replace( '/^wc-/', '', $fully_paid_status ) : 'fully-paid';

			if ( $parent->get_status() !== $fully_paid_status ) return;

			$this->trigger( $parent->get_id() );
		}

		/**
		 * trigger function.
		 *
		 * @access public
         *
		 * @return void
		 */
		function trigger( $order_id ){

			if( $order_id ){
				$this->object = wc_get_order( $order_id );
				if( ! $this->object ) return;

				// The transition is on the parent shop_order; never fire for a child.
				if( $this->object->get_type() === 'wcdp_payment' ) return;

				// Send once per order (guards against processing+completed both firing).
				if( $this->object->get_meta( '_wc_deposits_full_payment_email_sent' ) === 'yes' ) return;

                $this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
                $this->placeholders['{order_number}'] = $this->object->get_order_number();

			}

			if( ! $this->is_enabled() || ! $this->get_recipient() ){
				return;
			}

			$this->send( $this->get_recipient() , $this->get_subject() , $this->get_content() , $this->get_headers() , $this->get_attachments() );

			$this->object->update_meta_data( '_wc_deposits_full_payment_email_sent', 'yes' );
			$this->object->save();
		}

		/**
		 * get_content_html function.
		 *
		 * @access public
		 * @return string
		 */
		function get_content_html(){
			return wc_get_template_html( $this->template_html , array(
				'order' => $this->object ,
				'email_heading' => $this->get_heading() ,
                'additional_content' => version_compare( WOOCOMMERCE_VERSION, '3.7.0' ,'<') ?'' : $this->get_additional_content() ,                'sent_to_admin' => true ,
				'plain_text' => false ,
				'email' => $this
			) , '' , $this->template_base );
		}
		
		/**
		 * get_content_plain function.
		 *
		 * @access public
		 * @return string
		 */
		function get_content_plain(){
			
			return wc_get_template_html( $this->template_plain , array(
				'order' => $this->object ,
				'email_heading' => $this->get_heading() ,
                'additional_content' => version_compare( WOOCOMMERCE_VERSION, '3.7.0' ,'<') ?'' : $this->get_additional_content() ,                'sent_to_admin' => true ,
				'plain_text' => true ,
				'email' => $this
			) , '' , $this->template_base );
		}
		
		/**
		 * Initialise Settings Form Fields
		 *
		 * @access public
		 * @return void
		 */
		function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title' => esc_html__( 'Enable/Disable' , 'woocommerce-deposits' ) ,
					'type' => 'checkbox' ,
					'label' => esc_html__( 'Enable this email notification' , 'woocommerce-deposits' ) ,
					'default' => 'yes'
				) ,
				'recipient' => array(
					'title' => esc_html__( 'Recipient(s)' , 'woocommerce-deposits' ) ,
					'type' => 'text' ,
					'description' => sprintf( wp_kses(__( 'Enter recipients (comma separated) for this email. Defaults to <code>%s</code>.' , 'woocommerce-deposits' ), array('code'=>array()))  , esc_attr( get_option( 'admin_email' ) ) ) ,
					'placeholder' => '' ,
					'default' => ''
				) ,
				'subject' => array(
					'title' => esc_html__( 'Subject' , 'woocommerce-deposits' ) ,
					'type' => 'text' ,
					'description' => sprintf( wp_kses(__( 'This controls the email subject line. Leave blank to use the default subject: <code>%s</code>.' , 'woocommerce-deposits' ), array('code'=>array()))  , $this->subject ) ,
					'placeholder' => '' ,
					'default' => ''
				) ,
				'heading' => array(
					'title' => esc_html__( 'Email Heading' , 'woocommerce-deposits' ) ,
					'type' => 'text' ,
					'description' => sprintf( wp_kses(__( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: <code>%s</code>.' , 'woocommerce-deposits' ), array('code'=>array()))  , $this->heading ) ,
					'placeholder' => '' ,
					'default' => ''
				) ,
				'email_type' => array(
					'title' => esc_html__( 'Email type' , 'woocommerce-deposits' ) ,
					'type' => 'select' ,
					'description' => esc_html__( 'Choose which format of email to send.' , 'woocommerce-deposits' ) ,
					'default' => 'html' ,
					'class' => 'email_type' ,
					'options' => array(
						'plain' => esc_html__( 'Plain text' , 'woocommerce-deposits' ) ,
						'html' => esc_html__( 'HTML' , 'woocommerce-deposits' ) ,
						'multipart' => esc_html__( 'Multipart' , 'woocommerce-deposits' ) ,
					)
				)
			);
		}
	}

endif;

return new WC_Deposits_Email_Full_Payment();
