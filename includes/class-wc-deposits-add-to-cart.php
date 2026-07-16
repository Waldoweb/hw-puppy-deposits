<?php
/*Copyright: Â© 2026 Hostetler Web.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace DepositWP;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Deposits_Add_To_Cart
 */
class WC_Deposits_Add_To_Cart
{


    private $appointment_cost = null;


    /**
     * WC_Deposits_Add_To_Cart constructor.
     * @param $wc_deposits
     */
    public function __construct()
    {
        // Add the required styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 20);
        // NB: the deposit custom styles (wcdp_get_deposit_slider_custom_styles) are injected
        // once by the main plugin's enqueue_styles(); the duplicate injection that used to
        // live here (enqueue_inline_styles) was removed to stop the 3x-duplicated <style>.
        add_filter('woocommerce_bookings_booking_cost_string', array($this, 'calculate_bookings_cost'));

        //appointments plugin
        add_filter('woocommerce_appointments_appointment_cost_html', array($this, 'calculate_appointment_cost_html'));
        add_filter('appointment_form_calculated_appointment_cost', array($this, 'get_appointment_cost'), 100);
        // Hook the add to cart form

        add_action('woocommerce_before_add_to_cart_button', array($this, 'before_add_to_cart_button'), 999);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);

        //update html container via ajax listener
        add_action('wp_ajax_wc_deposits_update_deposit_container', array($this, 'ajax_update_deposit_container'));
        add_action('wp_ajax_nopriv_wc_deposits_update_deposit_container', array($this, 'ajax_update_deposit_container'));


        //grouped products handler
        add_filter('woocommerce_add_to_cart_product_id', array($this, 'grouped_products_handler'));

    }


    function ajax_update_deposit_container()
    {
        $price = isset($_POST['price']) ? $_POST['price'] : false;
        $product_id = isset($_POST['product_id']) ? $_POST['product_id'] : false;
        if ($product_id) {
            $deposit_slider_html = $this->get_deposit_container($product_id, $price);
            wp_send_json_success($deposit_slider_html);

        } else {
            wp_send_json_error();
        }
        wp_die();
    }


    /**
     * @brief Load the deposit-switch logic
     *
     * @return void
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script('wc-deposits-add-to-cart', WC_DEPOSITS_PLUGIN_URL . '/assets/js/add-to-cart.prod.js', array(), WC_DEPOSITS_VERSION);
        $message_deposit = get_option('wc_deposits_message_deposit');
        $message_full_amount = get_option('wc_deposits_message_full_amount');

        $message_deposit = stripslashes($message_deposit);
        $message_full_amount = stripslashes($message_full_amount);
        $allowed_html = array(
            'strong' => array(),
            'p' => array(),
            'br' => array(),
            'em' => array(),
            'b' => array(),
            's' => array(),
            'strike' => array(),
            'del' => array(),
            'u' => array(),
            'i' => array(),
            'a' => array(
                'target' => array(),
                'href' => array()
            )
        );
        $script_args = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'message' => array(
                'deposit' => wp_kses(__($message_deposit, 'woocommerce-deposits'), $allowed_html),
                'full' => wp_kses(__($message_full_amount, 'woocommerce-deposits'), $allowed_html),
            )
        );

        wp_localize_script('wc-deposits-add-to-cart', 'wc_deposits_add_to_cart_options', $script_args);

        // Tap/mobile support for the "Pay in Full unavailable" tooltip (hover is CSS-only).
        // Delegated on document so it survives the deposit form's AJAX re-render. Tapping the
        // disabled full label toggles the tooltip; tapping anywhere else closes it.
        $tip_js = <<<'JS'
( function () {
    function closeAll( except ) {
        document.querySelectorAll( '.wcdp-toggle__tip--visible' ).forEach( function ( t ) {
            if ( t !== except ) { t.classList.remove( 'wcdp-toggle__tip--visible' ); }
        } );
    }
    document.addEventListener( 'click', function ( e ) {
        var lbl = e.target.closest ? e.target.closest( '.wcdp-toggle__option--full' ) : null;
        if ( ! lbl ) { closeAll( null ); return; }
        var cont = lbl.closest( '.wcdp-toggle' );
        var input = cont && cont.querySelector( '.pay-full-amount' );
        var tip = cont && cont.querySelector( '.wcdp-toggle__tip' );
        if ( ! input || ! input.disabled || ! tip ) { closeAll( null ); return; }
        e.preventDefault();
        var showing = tip.classList.contains( 'wcdp-toggle__tip--visible' );
        closeAll( tip );
        tip.classList.toggle( 'wcdp-toggle__tip--visible', ! showing );
    } );
} )();
JS;
        wp_add_inline_script('wc-deposits-add-to-cart', $tip_js);

    }



    /**
     * get the updated booking cost and saves it to be used for html generation
     * @param $cost
     * @return mixed
     */
    public function get_appointment_cost($cost)
    {

        $this->appointment_cost = $cost;

        return $cost;

    }


    /**
     * @brief calculates new booking deposit on booking total change
     * @param $html
     * @return string
     */
    public function calculate_bookings_cost($html)
    {

        $posted = array();
        parse_str($_POST['form'], $posted);

        $product_id = $posted['add-to-cart'];
        $product = wc_get_product($product_id);
        if (version_compare(WC_BOOKINGS_VERSION, '1.15.0', '>=')) {

            $booking_data = wc_bookings_get_posted_data($posted, $product);
            $cost = \WC_Bookings_Cost_Calculation::calculate_booking_cost($booking_data, $product);
            if (is_wp_error($cost)) {
                return $html;
            }
            ob_start();
            ?>
            <script type="text/javascript">
                jQuery(function ($) {
                    'use strict';
                    var data = {
                        price:<?php echo $cost ?>,
                        product_id: <?php echo $product_id; ?> ,
                        trigger: 'woocommerce_bookings'
                    };
                    $(".wc-deposits-options-form").trigger('update_html', data);
                });


            </script>
            <?php
            $script = ob_get_clean();
            return $html . $script;
        }

        return $html;

    }

    /**
     * @param $html
     * @return string
     */
    public function calculate_appointment_cost_html($html)
    {

        $posted = array();

        parse_str($_POST['form'], $posted);

        $product_id = $posted['add-to-cart'];

        $appointment_cost = $this->appointment_cost;
        ob_start();
        ?>
        <script type="text/javascript">
            jQuery(function ($) {
                'use strict';
                var data = {
                    price:<?php echo $appointment_cost ?>,
                    product_id: <?php echo $product_id; ?> ,
                    trigger: 'woocommerce_bookings'
                };
                $(".wc-deposits-options-form").trigger('update_html', data);
            })

        </script>
        <?php
        $script = ob_get_clean();
        return $html . $script;

    }

    function get_deposit_container($product_id, $price = false, $args = array())
    {
        //todo :  fix display price including tax option for fixed & percentage , it is currently always displaying amount including tax
        $basic_buttons = get_option('wc_deposits_use_basic_radio_buttons', true) === 'yes';
        $ajax_refresh =  apply_filters('wc_deposits_product_disable_deposit_ajax_refresh',false,$product_id) ? 'no':'yes';
        ob_start(); ?>
        <div  data-product_id="<?php echo $product_id; ?>" style="height:20px; width:100%;"  data-ajax-refresh="<?php echo $ajax_refresh; ?>" class='depositwp_single_deposit_form <?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>'></div>
        <?php
        $html = ob_get_clean(); // always return empty div

        $default_args = array('show_add_to_cart_button' => false);
        $args = ($args) ? wp_parse_args($args, $default_args) : $default_args;

        if (!$product_id) return '';
        $product = wc_get_product($product_id);
        if (!$product) return '';
        $product_type = $product->get_type();

        //if product is variable , check variations override for product deposit
        if ($product_type === 'variable') {

            $deposit_enabled = wc_deposits_is_product_deposit_enabled($product_id);
            if (!$deposit_enabled) {
                foreach ($product->get_children() as $variation_id) {
                    //if not enabled on global level , check in overrides
                    $variation = wc_get_product($variation_id);
                    if (!is_object($variation)) {
                        continue;
                    }
                    //check override
                    $override = $variation->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

                    if ($override) {
                        $variation_deposit_enabled = wc_deposits_is_product_deposit_enabled($variation_id);
                        if ($variation_deposit_enabled) {
                            //at least 1 variation has deposit enabled
                            $deposit_enabled = true;
                            break;
                        }
                    }
                }

            }

        } else {
            $deposit_enabled = wc_deposits_is_product_deposit_enabled($product_id);
        }


        //check if all variations disabled
        if (!wp_doing_ajax() && !$deposit_enabled) {
            return '';
        } elseif (wp_doing_ajax() && !$deposit_enabled) {
            return $html;
        }

        if (!$price) $price = $product->get_price();
        $amount_type = wc_deposits_get_product_deposit_amount_type($product_id);
        $force_deposit = wc_deposits_is_product_deposit_forced($product_id);
        $deposit_amount = wc_deposits_get_product_deposit_amount($product_id);
        $deposits_enable_per_person = $product->get_meta('_wc_deposits_enable_per_person', true);
        $tax_display = get_option('wc_deposits_tax_display') === 'yes';
        $tax_handling = get_option('wc_deposits_taxes_handling','split');
        $tax = 0;
        $has_payment_plans = false;

        if ($tax_display && $tax_handling === 'deposit') {
            $tax = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));
        } elseif ($tax_display && $tax_handling === 'split') {

            $tax_total = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));

            $division = $price == 0 ? 1 : $price;
            $deposit_percentage = $deposit_amount * 100 / $division;


            if ($amount_type === 'percent') {
                $deposit_percentage = $deposit_amount;
            }
            $tax = $tax_total * $deposit_percentage / 100;

        }

        switch ($amount_type) {

            case 'fixed':
                //if prices inclusive of tax
                $amount = $deposit_amount;
                if(!is_numeric($amount)) return $html;
                $amount = round($amount, wc_get_price_decimals());

                if($tax_display){
                    $amount = $deposit_amount + $tax;
                }

                break;
            case 'percent':

                if ($product_type === 'variable' || $product_type === 'composite' || $product_type === 'booking' && !wp_doing_ajax()) {
                    $amount = $deposit_amount;
                } elseif ($product_type === 'subscription' && class_exists('WC_Subscriptions_Product')) {
                    $total_signup_fee = \WC_Subscriptions_Product::get_sign_up_fee($product);
                    $amount = $total_signup_fee * ($deposit_amount / 100.0);
                } else {
                    $amount =  floatval($price) * ($deposit_amount / 100.0);
                }

                if ($tax_display) {
                    $amount += $tax;
                }


                $amount = round($amount, wc_get_price_decimals());
                break;
            case 'payment_plan':
                //payment plan
                $available_plans_meta = wc_deposits_get_product_available_plans($product_id);
                if (empty($available_plans_meta)) return $html;

                $has_payment_plans = true;
                $payment_plans = array();
                //payment plans
                $available_plans = get_terms(array(
                        'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
                        'hide_empty' => false,
                        'include' => $available_plans_meta,
                    )
                );
                foreach($available_plans as $available_plan){
                    $plan_id = $available_plan->term_id;


                    //get the plan amounts type , percentage or fixed.
                    $plan_amount_type = get_term_meta($plan_id, 'amount_type', true);
                    if (empty($plan_amount_type)) $plan_amount_type = 'percentage'; // backward compatiblity ,fallback to percentage if type not detected


                    //details
                    $plan_payment_details = get_term_meta($plan_id, 'payment_details', true);
                    if(!is_string($plan_payment_details) || empty($plan_payment_details)) continue;
                    $plan_payment_details = json_decode($plan_payment_details, true);



                    if (!is_array($plan_payment_details['payment-plan']) || empty($plan_payment_details['payment-plan'])) {
                        continue; // invalid plan details
                    }


                    $payment_plans[$available_plan->term_id] = array(
                        'name' => $available_plan->name,
                        'description' => $available_plan->description,
                    );


                    //set the amount for each payment
                    if($plan_amount_type === 'fixed'){
                        $plan_deposit_amount = get_term_meta($plan_id, 'deposit_percentage', true);
                        $plan_total = floatval($plan_deposit_amount) + array_sum(array_column($plan_payment_details['payment-plan'], 'percentage'));
                        $plan_total_record = $plan_total;
                    } else {
                        //get deposit percentage from meta
                        $plan_deposit_percentage = get_term_meta($plan_id, 'deposit_percentage', true);
                        $payment_plans[$available_plan->term_id]['deposit_percentage'] = $plan_deposit_percentage;;
                        //we need to calculate total cost in case it is more than 100%
                        $plan_total_percentage = $plan_deposit_percentage + array_sum(array_column($plan_payment_details['payment-plan'], 'percentage'));

                        // prepare display of payment plans
                        $plan_total = $price / 100 * $plan_total_percentage;
                        $plan_total = round($plan_total, wc_get_price_decimals());
                        $plan_total_record = $plan_total;
                        $plan_deposit_amount = $price / 100 * $plan_deposit_percentage;
                    }

                    $plan_total_record -= $plan_deposit_amount;
                    $plan_tax_total = wc_get_price_including_tax($product, array('price' => $plan_total)) - wc_get_price_excluding_tax($product, array('price' => $plan_total));
                    $plan_tax_total_record = $plan_tax_total;
                    //adjust plan total for display

                    switch ($tax_handling) {
                        case 'deposit' :
                            $plan_deposit_tax = $plan_tax_total;
                            break;
                        case 'split' :
                            //default tax is the split
                            $plan_deposit_tax = wc_get_price_including_tax($product, array('price' => $plan_deposit_amount)) - wc_get_price_excluding_tax($product, array('price' => $plan_deposit_amount));
                            break;
                        default :
                            $plan_deposit_tax = 0.0;
                            break;
                    }

                    if (wc_prices_include_tax() && !$tax_display) {
                        $plan_deposit_amount -= $plan_deposit_tax;
                        $plan_tax_total_record -= $plan_deposit_tax;
                    } elseif(!wc_prices_include_tax() && $tax_display){
                        $plan_deposit_amount += $plan_deposit_tax;
                        $plan_tax_total_record -= $plan_deposit_tax;

                    }
                    $count = 0;
                    foreach ($plan_payment_details['payment-plan'] as $key => $payment_line) {
                        $count++;

                        if ($plan_amount_type === 'fixed') {
                            $line_percentage = round($payment_line['percentage'] / $plan_total * 100, 1);
                        } else {
                            $line_percentage = $payment_line['percentage'];
                        }

                        if ($count === count($plan_payment_details['payment-plan'])) {
                            $line_amount = $plan_total_record;
                            $line_tax = $plan_tax_total_record;

                            if (wc_prices_include_tax() && !$tax_display) {

                                $line_amount -= $line_tax;
                            } elseif(!wc_prices_include_tax() && $tax_display){
                                $line_amount += $line_tax;
                            }


                        } else {
                            $line_amount = $plan_amount_type === 'fixed' ? round($payment_line['percentage'], wc_get_price_decimals()) : round($price / 100 * $payment_line['percentage'], wc_get_price_decimals());
                            $plan_total_record -= $line_amount;
                            //set the tax for each payment
                            switch ($tax_handling) {
                                case 'deposit' :
                                    $line_tax = 0.0;
                                    break;
                                case 'split' :
                                    //default tax is the split
                                    $line_tax = round(wc_get_price_including_tax($product, array('price' => $line_amount)) - wc_get_price_excluding_tax($product, array('price' => $line_amount)), wc_get_price_decimals());
                                    break;
                                default :
                                    //the tax is being split on all partial payments except deposit
                                    $line_tax = round(($plan_tax_total / 100 * $line_percentage), wc_get_price_decimals());
                                    break;
                            }                            $plan_tax_total_record -= $line_tax;

                            if (wc_prices_include_tax() && !$tax_display) {
                                $line_amount -= $line_tax;

                            } elseif(!wc_prices_include_tax() && $tax_display){
                                $line_amount += $line_tax;
                            }

                        }

                        $plan_payment_details['payment-plan'][$key]['line_amount'] = $line_amount;
                        $plan_payment_details['payment-plan'][$key]['line_tax'] = $line_tax;
                    }

                    //adjust plan total for display
                    if (wc_prices_include_tax() && !$tax_display) {
                        $plan_total -= $plan_tax_total;

                    } elseif(!wc_prices_include_tax() && $tax_display){
                        $plan_total += $plan_tax_total;
                    }

                    $payment_plans[$available_plan->term_id]['plan_total'] = $plan_total;
                    $payment_plans[$available_plan->term_id]['deposit_amount'] = $plan_deposit_amount;
                    $payment_plans[$available_plan->term_id]['details'] = $plan_payment_details;

                }
                break;


        }


        /*** TAX DISPLAY ***/
        if(!$has_payment_plans){
            if ( wc_prices_include_tax() && !$tax_display) {
                //partial payments will automatically contain tax if prices include tax, so we have to remove them for display purpose
                $deposit_amount -= wc_get_price_including_tax($product, array('price' => $deposit_amount)) - wc_get_price_excluding_tax($product, array('price' => $deposit_amount));

            } elseif (!wc_prices_include_tax() && $tax_display) {
                $deposit_amount += wc_get_price_including_tax($product, array('price' => $deposit_amount)) - wc_get_price_excluding_tax($product, array('price' => $deposit_amount));
            }
        }



        if (wc_prices_include_tax()) {

            if ($tax_display && $tax_handling === 'deposit') {
                $tax = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));
            } elseif ($tax_display && $tax_handling === 'split') {

                $tax_total  = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));
                $division = $price == 0 ? 1 : $price;

                $deposit_percentage = $deposit_amount * 100 / $division;

                if ($amount_type === 'percent') {
                    $deposit_percentage = $deposit_amount;
                }

            }
        }

        $products_deposit_higher = array('variable', 'booking', 'accommodation-booking', 'appointment'); //product types where the deposit could be higher than registered price
        if (apply_filters('wc_deposits_product_disable_if_deposit_higher_than_price', true) && $amount_type !== 'payment_plan' && !in_array($product_type, $products_deposit_higher) && $amount >= $price) {
            //debug information
            return $html;
        }

        //suffix
        if ($amount_type === 'fixed') {

            if ($product_type === 'booking' && method_exists($product,'has_persons') 
            //@phpcs:disable
            && $product->has_persons() && $deposits_enable_per_person === 'yes') {
                $suffix = esc_html__('per person', 'woocommerce-deposits');
            } elseif ($product_type === 'booking') {
                $suffix = esc_html__('per booking', 'woocommerce-deposits');
            } elseif (!$product->is_sold_individually()) {
                $suffix = esc_html__('per item', 'woocommerce-deposits');
            } else {
                $suffix = '';
            }

        } else {

            if (!wp_doing_ajax() && $product_type === 'booking' || $product_type === 'composite') {
                $amount = '<span class=\'amount\'>' . round($deposit_amount, wc_get_price_decimals()) . '%' . '</span>';

            }

            if (!$product->is_sold_individually()) {
                $suffix = esc_html__('per item', 'woocommerce-deposits');
            } else {
                $suffix = '';
            }
        }

        $default_checked = get_option('wc_deposits_default_option', 'deposit');
        $deposit_text = get_option('wc_deposits_button_deposit', esc_html__('Pay Deposit', 'woocommerce-deposits'));
        $full_text = get_option('wc_deposits_button_full_amount', esc_html__('Full Amount', 'woocommerce-deposits'));
        $deposit_option_text = get_option('wc_deposits_deposit_option_text', esc_html__('Deposit Option', 'woocommerce-deposits'));


        $local_args = array(
            'deposit_info' => array(
                //raw amount before calculations
                'type' => $amount_type,
                'amount' => $deposit_amount,
            ),
            'product' => $product,
            'ajax_refresh' => $ajax_refresh,
            'tax_display' => $tax_display,
            'suffix' => $suffix,
            'force_deposit' => $force_deposit ? 'yes' : 'no',
            'basic_buttons' => $basic_buttons,
            'deposit_text' => $deposit_text,
            'full_text' => $full_text,
            'deposit_option_text' => $deposit_option_text,
            'default_checked' => $default_checked,
            'has_payment_plans' => false
        );
        if ($has_payment_plans) {
            $local_args['has_payment_plans'] = $has_payment_plans;
            $local_args['payment_plans'] = $payment_plans;
            $local_args['deposit_amount'] = '';
        } else {
            $local_args['deposit_amount'] = $amount;
        }
        
        $args = ($args) ? wp_parse_args($args, $local_args) : $local_args;
        $args = apply_filters('wc_deposits_product_slider_args', $args, $product_id);

        ob_start();
        wc_get_template('single-product/wc-deposits-product-slider.php', $args, '', WC_DEPOSITS_TEMPLATE_PATH);
        $html = ob_get_clean();

        return $html;
    }


    /**
     * @brief deposit calculation and display
     */
    public
    function before_add_to_cart_button()
    {
        //deposit already queued
//        if (is_product() && did_action('wc_deposits_enqueue_product_scripts')) return;
        //user restriction
        if (is_user_logged_in()) {

            $disabled_user_roles = get_option('wc_deposits_disable_deposit_for_user_roles', array());
            if (!empty($disabled_user_roles)) {

                foreach ($disabled_user_roles as $disabled_user_role) {

                    if (wc_current_user_has_role($disabled_user_role)) return;
                }
            }
        } else {
            $allow_deposit_for_guests = get_option('wc_deposits_restrict_deposits_for_logged_in_users_only', 'no');

            if ($allow_deposit_for_guests !== 'no') return;
        }

        global $product;

        $product_id = $product->get_id();
        echo $this->get_deposit_container($product_id);

    }


    /**
     * @param $cart_item_meta
     * @param $product_id
     * @param $variation_id
     * @return mixed
     */
    public
    function add_cart_item_data($cart_item_meta, $product_id, $variation_id)
    {

        //user restriction
        if (!apply_filters('wc_deposits_deposit_enabled_for_customer', true)) {
            return $cart_item_meta;
        }

        $default = get_option('wc_deposits_default_option');
        $product = wc_get_product($product_id);
        if(!$product) return $cart_item_meta;
        $override = apply_filters('wc_deposits_add_to_cart_deposit_override', array(), $product_id, $variation_id);
        if ($product->get_type() === 'variable') {

            $deposit_enabled = $override['enable'] ?? wc_deposits_is_product_deposit_enabled($variation_id);
            $force_deposit = $override['force'] ?? wc_deposits_is_product_deposit_forced($variation_id);
        } else {
            $deposit_enabled = $override['enable'] ?? wc_deposits_is_product_deposit_enabled($product_id);
            $force_deposit = $override['force'] ?? wc_deposits_is_product_deposit_forced($product_id);
        }

        if ($deposit_enabled) {
            if (!isset($_REQUEST[$product_id . '-deposit-radio'])) {
                $_REQUEST[$product_id . '-deposit-radio'] = $default ? $default : 'deposit';
            }

            if (isset($variation_id) && isset($_REQUEST[$variation_id . '-deposit-radio'])) {
                $_REQUEST[$product_id . '-deposit-radio'] = $_REQUEST[$variation_id . '-deposit-radio'];

                if (isset($_REQUEST[$variation_id . '-selected-plan'])) {
                    $_REQUEST[$product_id . '-selected-plan'] = $_REQUEST[$variation_id . '-selected-plan'];
                }
            }

            $cart_item_meta['deposit'] = array(
                'enable' => $force_deposit ? 'yes' : ($_REQUEST[$product_id . '-deposit-radio'] === 'full' ? 'no' : 'yes')
            );

            if(isset($override['enable']) && $override['enable'] ) $cart_item_meta['deposit']['enable'] = 'yes';
            if ($cart_item_meta['deposit']['enable'] === 'yes') {
                if ((isset($_REQUEST[$product_id . '-selected-plan']))) {
                    //payment plan selected
                    $cart_item_meta['deposit']['payment_plan'] = $_REQUEST[$product_id . '-selected-plan'];

                } elseif (wc_deposits_get_product_deposit_amount_type($product_id) === 'payment_plan') {
                    // default selection is deposit  and deposit type is payment plan, so pick the first payment plan

                    $available_plans = $variation_id ? wc_deposits_get_product_available_plans($variation_id) : wc_deposits_get_product_available_plans($product_id);
                    if (is_array($available_plans)) {
                        $cart_item_meta['deposit']['payment_plan'] = $available_plans[0];

                    }
                }
                if (isset($override['payment_plan'])) {
                    $cart_item_meta['deposit']['payment_plan'] = $override['payment_plan'];
                }
            }

        }
        $cart_item_meta['deposit']['override'] = $override;
        return $cart_item_meta;
    }

    /**
     * Set deposit values for all added products based on the grouped product deposit value
     * @param $product_id
     * @return mixed
     */
    function grouped_products_handler($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'grouped') return $product_id;
        $_REQUEST['wcdp_grouped_by'] = $product_id;
        add_filter('wc_deposits_add_to_cart_deposit_override', array($this, 'grouped_data_override'));
        return $product_id;
    }

    function grouped_data_override($override)
    {

        if (isset($_REQUEST['wcdp_grouped_by'])) {

            $grouped_product_id = $_REQUEST['wcdp_grouped_by'];
            $grouped_product = wc_get_product($grouped_product_id);
            if ($grouped_product) {
                $override['enable'] = wc_deposits_is_product_deposit_enabled($grouped_product_id);
                $override['force'] = wc_deposits_is_product_deposit_forced($grouped_product_id);
                $override['amount'] = floatval($grouped_product->get_meta('_wc_deposits_deposit_amount', true));
                $override['amount_type'] = $grouped_product->get_meta('_wc_deposits_amount_type', true);

                if (isset($_REQUEST[$grouped_product_id . '-deposit-radio'])) {
                    if($override['force']) $override['enable']  = true;
                    $override['enable'] = $_REQUEST[$grouped_product_id . '-deposit-radio'] === 'deposit';
                }

                if ($override['amount_type'] === 'payment_plan') {
                    if ((isset($_REQUEST[$grouped_product_id . '-selected-plan']))) {
                        //payment plan selected
                        $override['payment_plan'] = $_REQUEST[$grouped_product_id . '-selected-plan'];
                    } else {
                        // default selection is deposit  and deposit type is payment plan, so pick the first payment plan
                        $available_plans = wc_deposits_get_product_available_plans($grouped_product_id);

                        if (is_array($available_plans)) {
                            $override['payment_plan'] = $available_plans[0];
                        }
                    }
                }
            }
        }

        return $override;
    }
}
