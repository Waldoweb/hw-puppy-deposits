<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
define ( 'WcdpCheckoutBlock_VERSION', '0.1.0' );

/**
 * Class for integrating with WooCommerce Blocks
 */

function get_block_handles(){
    return array(
        'partial-payments-details',
        'deposit-slider'
    );

}
class Wc_Deposits_Blocks_Integration implements IntegrationInterface {



	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'woocommerce-deposits-blocks';
	}

	/**
	 * When called invokes any initialization/setup for the integration.
	 */
	public function initialize() {
		$this->register_block_frontend_scripts();
		$this->register_block_editor_scripts();
        $this->register_main_integration();
		

		//this enqueues the styles in the iframe but everywhere also 
		add_action('enqueue_block_assets', array($this, 'register_block_styles'));
	}

	/**
	 * Registers the main JS file required to add filters and Slot/Fills.
	 */
	public function register_main_integration() {

		$script_path =   WC_DEPOSITS_PLUGIN_PATH.'/build/index.js';

		$script_url = WC_DEPOSITS_PLUGIN_URL.'/includes/blocks/build/index.js';
		$script_asset_path = dirname( __FILE__ ) . '/build/index.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_path ),
			);
			

	
		wp_register_script(
			'woocommerce-deposits-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
		wp_set_script_translations(
			'woocommerce-deposits-blocks-integration',
			'woocommerce-deposits',
			dirname( __FILE__ ) . '/locale'
		);
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {

        $handles = array( 'woocommerce-deposits-blocks-integration');

        foreach(get_block_handles() as $handle){
            $handles[] = 'woocommerce-deposits-blocks-'.$handle.'-block-frontend';

        }

        return $handles;
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
        $handles = array( 'woocommerce-deposits-blocks-integration');

        foreach(get_block_handles() as $handle){
            $handles[] = 'woocommerce-deposits-blocks-'.$handle.'-block-editor';

        }

		

        return $handles;

	}

	function get_plans_details($plans_ids){

		
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {



		if(WCDP()->is_disabled()){
			return array(	
				'disabled' => true
			);
		
		}
        
		
		
		
		$force_deposit = get_option('wc_deposits_checkout_mode_force_deposit') === 'yes';

		$deposit_amount = get_option('wc_deposits_checkout_mode_deposit_amount');
		$amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');
		
		$message_deposit = get_option('wc_deposits_message_deposit');
        $message_full_amount = get_option('wc_deposits_message_full_amount');



		//todo check if the deposit amount is greater than the total amount
		// if ($amount_type === 'fixed' && $deposit_amount >= WC()->cart->get_total('edit')) {
		// 	$active = false;
		// }



		

		$default_checked = get_option('wc_deposits_default_option', 'deposit');
		$basic_buttons = get_option('wc_deposits_use_basic_radio_buttons', true) === 'yes';

		$to_pay_text = esc_html__(get_option('wc_deposits_to_pay_text'), 'woocommerce-deposits');
		$future_payments_text = esc_html__(get_option('wc_deposits_second_payment_text'), 'woocommerce-deposits');
		$deposit_text = esc_html__(get_option('wc_deposits_button_deposit'), 'woocommerce-deposits');
		$full_text = esc_html__(get_option('wc_deposits_button_full_amount'), 'woocommerce-deposits');
		$deposit_option_text = esc_html__(get_option('wc_deposits_deposit_option_text'), 'woocommerce-deposits' );
	
		$available_plans_meta = WCDP()->cart::get_checkout_mode_available_payment_plans();
		$available_plans = get_terms(array(
				'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
				'hide_empty' => false,
				'include' => $available_plans_meta,
			)
		);

		$options = array(
			'checkout_mode' => get_option('wc_deposits_checkout_mode_enabled', 'no') === 'yes',
			'amount_type' => $amount_type,
			'deposit_amount' => $deposit_amount,
			'force_deposit' => $force_deposit,
			'deposit_text' => $deposit_text,
            'basic_buttons' => $basic_buttons,
			'full_text' => $full_text,
			'to_pay_text' => $to_pay_text,
			'future_payments_text' => $future_payments_text,
			'deposit_option_text' => $deposit_option_text,
			'default_checked' =>   $default_checked,
			'payment_plans' => $available_plans,
            'message_deposit' => $message_deposit,
            'message_full_amount' => $message_full_amount,
			// 'hide_when_forced' intentionally not passed — the setting was removed.
			// build/woocommerce-deposits-blocks-deposit-slider.js still destructures it
			// and ANDs it with force_deposit; absent, it reads undefined and the deposit
			// UI never hides, which is the behaviour we want. There is no JS source in
			// the fork to rebuild, so the key must stay gone rather than be set false.
		);


		$data = array(
			
			'options' => $options,
		);

		return $data;
	}

    public function register_block_styles() {
		

		
		
		if(has_block('woocommerce/checkout')){

			foreach(get_block_handles() as $handle) {
				//get script assets
				 
				$style_path  = WC_DEPOSITS_PLUGIN_PATH.'/includes/blocks/build/woocommerce-deposits-blocks-'.$handle.'.min.css';
				$style_url  = WC_DEPOSITS_PLUGIN_URL.'/includes/blocks/build/woocommerce-deposits-blocks-'.$handle.'.min.css';
			
				wp_enqueue_style(
					'woocommerce-deposits-blocks-'.$handle.'-block',
					$style_url,
					['wp-block-editor'],
					$this->get_file_version( $style_path )
				);
	
				if($handle ==='deposit-slider'){
					$custom_styles = wcdp_get_deposit_slider_custom_styles();
					wp_add_inline_style('woocommerce-deposits-blocks-'.$handle.'-block', $custom_styles);
				}
	
			}
		}

    }



    public function register_block_editor_scripts() {

		//always needed
		wp_enqueue_script('wp-date');

        foreach(get_block_handles() as $handle) {

            $script_url        =  WC_DEPOSITS_PLUGIN_URL.'/includes/blocks/build/woocommerce-deposits-blocks-'.$handle.'.js';
			$script_asset_path = WC_DEPOSITS_PLUGIN_PATH.'/includes/blocks/build/woocommerce-deposits-blocks-'.$handle.'.asset.php';
			
            $script_asset      = file_exists( $script_asset_path )
                ? require $script_asset_path
                : array(
                    'dependencies' => array(),
                    'version'      => $this->get_file_version( $script_asset_path ),
                );


            wp_register_script(
                'woocommerce-deposits-blocks-'.$handle.'-block-editor',
                $script_url,
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );


            wp_set_script_translations(
                'woocommerce-deposits-blocks-'.$handle.'-block-editor',
                'woocommerce-deposits',
                WC_DEPOSITS_PLUGIN_PATH.'/locale'
            );
        }

    }

    public function register_block_frontend_scripts() {



        foreach(get_block_handles() as $handle){

			
            
			 $script_url = WC_DEPOSITS_PLUGIN_URL.'/includes/blocks/build/woocommerce-deposits-blocks-'.$handle.'.js';
            
            $script_asset_path = WC_DEPOSITS_PLUGIN_PATH.  '/build/woocommerce-deposits-blocks-'.$handle.'.asset.php';
            $script_asset      = file_exists( $script_asset_path )
                ? require $script_asset_path
                : array(
                    'dependencies' => array(),
                    'version'      => $this->get_file_version( $script_asset_path ),
                );


            wp_register_script(
                'woocommerce-deposits-blocks-'.$handle.'-block-frontend',
                $script_url,
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );
            wp_set_script_translations(
                'woocommerce-deposits-blocks-'.$handle.'-block-frontend',
                'woocommerce-deposits',
                WC_DEPOSITS_PLUGIN_PATH.'/locale'
            );

        }

    }

	/**
	 * Get the file modified time as a cache buster if we're in dev mode.
	 *
	 * @param string $file Local path to the file.
	 * @return string The cache buster value to use for the given file.
	 */
	protected function get_file_version( $file ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return filemtime( $file );
		}
		return WcdpCheckoutBlock_VERSION;
	}
}
