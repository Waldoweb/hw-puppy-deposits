<?php
/*Copyright: © 2026 Hostetler Web.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/


use DepositWP\WC_Deposits_Admin_Order;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @return mixed
 */
function wc_deposits_deposit_breakdown_tooltip()
{

    $display_tooltip = get_option('wc_deposits_breakdown_cart_tooltip') === 'yes';


    $tooltip_html = '';

    if ($display_tooltip && isset(WC()->cart->deposit_info['deposit_breakdown']) && is_array(WC()->cart->deposit_info['deposit_breakdown'])) {

        $labels = apply_filters('wc_deposits_deposit_breakdown_tooltip_labels', array(
            'cart_items' => esc_html__('Cart items', 'woocommerce-deposits'),
            'fees' => esc_html__('Fees', 'woocommerce-deposits'),
            'taxes' => esc_html__('Tax', 'woocommerce-deposits'),
            'shipping' => esc_html__('Shipping', 'woocommerce-deposits'),
        ));

        $deposit_breakdown = WC()->cart->deposit_info['deposit_breakdown'];
        $tip_information = '<ul>';
        foreach ($deposit_breakdown as $component_key => $component) {

            if (!isset($labels[$component_key])) continue;
            if ($component === 0) {
                continue;
            }
            switch ($component_key) {
                case 'cart_items' :
                    $tip_information .= '<li>' . $labels['cart_items'] . ' : ' . wc_price($component) . '</li>';

                    break;
                case 'fees' :
                    $tip_information .= '<li>' . $labels['fees'] . ' : ' . wc_price($component) . '</li>';
                    break;
                case 'taxes' :
                    $tip_information .= '<li>' . $labels['taxes'] . ' : ' . wc_price($component) . '</li>';

                    break;
                case 'shipping' :
                    $tip_information .= '<li>' . $labels['shipping'] . ' : ' . wc_price($component) . '</li>';

                    break;

                default :
                    break;
            }
        }

        $tip_information .= '</ul>';

        $tooltip_html = '<span id="deposit-help-tip" data-tip="' . esc_attr($tip_information) . '">&#63;</span>';
    }

    return apply_filters('woocommerce_deposits_tooltip_html', $tooltip_html);
}


/** http://jaspreetchahal.org/how-to-lighten-or-darken-hex-or-rgb-color-in-php-and-javascript/
 * @param $color_code
 * @param int $percentage_adjuster
 * @return array|string
 * @author Jaspreet Chahal
 */
function wc_deposits_adjust_colour($color_code, $percentage_adjuster = 0)
{
    $percentage_adjuster = round($percentage_adjuster / 100, 2);

    if (is_array($color_code)) {
        $r = $color_code["r"] - (round($color_code["r"]) * $percentage_adjuster);
        $g = $color_code["g"] - (round($color_code["g"]) * $percentage_adjuster);
        $b = $color_code["b"] - (round($color_code["b"]) * $percentage_adjuster);

        $adjust_color = array("r" => round(max(0, min(255, $r))),
            "g" => round(max(0, min(255, $g))),
            "b" => round(max(0, min(255, $b))));
    } elseif (preg_match("/#/", $color_code)) {
        $hex = str_replace("#", "", $color_code);
        $r = (strlen($hex) == 3) ? hexdec(substr($hex, 0, 1) . substr($hex, 0, 1)) : hexdec(substr($hex, 0, 2));
        $g = (strlen($hex) == 3) ? hexdec(substr($hex, 1, 1) . substr($hex, 1, 1)) : hexdec(substr($hex, 2, 2));
        $b = (strlen($hex) == 3) ? hexdec(substr($hex, 2, 1) . substr($hex, 2, 1)) : hexdec(substr($hex, 4, 2));
        $r = round($r - ($r * $percentage_adjuster));
        $g = round($g - ($g * $percentage_adjuster));
        $b = round($b - ($b * $percentage_adjuster));

        $adjust_color = "#" . str_pad(dechex(max(0, min(255, $r))), 2, "0", STR_PAD_LEFT)
            . str_pad(dechex(max(0, min(255, $g))), 2, "0", STR_PAD_LEFT)
            . str_pad(dechex(max(0, min(255, $b))), 2, "0", STR_PAD_LEFT);

    } else {
        $adjust_color = new WP_Error('', 'Invalid Color format');
    }


    return $adjust_color;
}



/**
 * @return bool
 */
function wcdp_checkout_mode()
{

    return get_option('wc_deposits_checkout_mode_enabled') === 'yes';
}



/**
 * @param $product
 * @return float
 */
function wc_deposits_calculate_product_deposit($product)
{


    $deposit_enabled = wc_deposits_is_product_deposit_enabled($product->get_id());
    $product_type = $product->get_type();
    if ($deposit_enabled) {


        $deposit = wc_deposits_get_product_deposit_amount($product->get_id());
        $amount_type = wc_deposits_get_product_deposit_amount_type($product->get_id());


        $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax');

        if ($woocommerce_prices_include_tax === 'yes') {

            $amount = wc_get_price_including_tax($product);

        } else {
            $amount = wc_get_price_excluding_tax($product);

        }

        switch ($product_type) {


            case 'subscription' :
                if (class_exists('WC_Subscriptions_Product')) {

                    $amount = \WC_Subscriptions_Product::get_sign_up_fee($product);
                    if ($amount_type === 'fixed') {
                    } else {
                        $deposit = $amount * ($deposit / 100.0);
                    }

                }
                break;
            case 'yith_bundle' :
                $amount = $product->price_per_item_tot;
                if ($amount_type === 'fixed') {
                } else {
                    $deposit = $amount * ($deposit / 100.0);
                }
                break;
            case 'variable' :

                if ($amount_type === 'fixed') {
                } else {
                    $deposit = $amount * ($deposit / 100.0);
                }
                break;

            default:


                if ($amount_type !== 'fixed') {

                    $deposit = $amount * ($deposit / 100.0);
                }

                break;
        }

        return floatval($deposit);
    }
}

/**
 * @brief checks if deposit is enabled for product
 * @param $product_id
 * @return mixed
 */
function wc_deposits_is_product_deposit_enabled($product_id)
{
    $enabled = false;
    $product = wc_get_product($product_id);
    if ($product) {

        // if it is a variation , check variation directly
        if ($product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            $inherit = $parent->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $enabled = get_option('wc_deposits_storewide_deposit_enabled', 'no') === 'yes';
            } else {
                $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

                if ($override) {
                    $enabled = $product->get_meta('_wc_deposits_enable_deposit', true) === 'yes';
                } else {
                    $enabled = $parent->get_meta('_wc_deposits_enable_deposit', true) === 'yes';
                }
            }


        } else {

            $inherit = $product->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $enabled = get_option('wc_deposits_storewide_deposit_enabled', 'no') === 'yes';
            } else {
                $enabled = $product->get_meta('_wc_deposits_enable_deposit', true) === 'yes';
            }
        }


    }

    return apply_filters('wc_deposits_product_enable_deposit', $enabled, $product_id);

}

/**
 * @brief Whether a product sits in a category excluded from storewide deposits.
 *
 * Checks the product's own categories plus every ancestor of those categories, so
 * excluding a parent category also excludes everything filed under its children.
 * Variations carry no categories of their own, so they resolve to their parent.
 *
 * @param int $product_id
 * @return bool
 */
function wc_deposits_is_product_category_excluded($product_id)
{
    $excluded = get_option('wc_deposits_storewide_deposit_excluded_categories', array());

    if (empty($excluded) || !is_array($excluded)) {
        return false;
    }

    $product = wc_get_product($product_id);

    if (!$product) {
        return false;
    }

    // Variations inherit their parent's categories.
    if ($product->get_type() === 'variation') {
        $product_id = $product->get_parent_id();
    }

    $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

    if (is_wp_error($terms) || empty($terms)) {
        return false;
    }

    // Walk up from each assigned category so children of an excluded parent match too.
    $terms_to_check = $terms;
    foreach ($terms as $term_id) {
        $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');
        if (!empty($ancestors)) {
            $terms_to_check = array_merge($terms_to_check, $ancestors);
        }
    }

    $excluded = array_map('absint', $excluded);

    return (bool) array_intersect($terms_to_check, $excluded);
}

/**
 * @brief Hide the deposit option for products in an excluded category.
 *
 * Only applies to products inheriting the storewide settings — an explicit
 * per-product deposit setting always wins over the category exclusion.
 *
 * @param bool $enabled
 * @param int $product_id
 * @return bool
 */
function wc_deposits_exclude_categories_from_deposit($enabled, $product_id)
{
    if (!$enabled) {
        return $enabled;
    }

    $product = wc_get_product($product_id);

    if (!$product) {
        return $enabled;
    }

    // Per-product settings live on the parent for variations.
    $settings_holder = $product->get_type() === 'variation'
        ? wc_get_product($product->get_parent_id())
        : $product;

    if (!$settings_holder) {
        return $enabled;
    }

    $inherit = $settings_holder->get_meta('_wc_deposits_inherit_storewide_settings');

    // Product opted out of storewide settings — leave its own choice alone.
    if (!empty($inherit) && $inherit !== 'yes') {
        return $enabled;
    }

    if (wc_deposits_is_product_category_excluded($product_id)) {
        return false;
    }

    return $enabled;
}

add_filter('wc_deposits_product_enable_deposit', 'wc_deposits_exclude_categories_from_deposit', 10, 2);

function wc_deposits_is_product_deposit_forced($product_id)
{
    $forced = false;
    $product = wc_get_product($product_id);
    if ($product) {

        // if it is a variation , check variation directly
        if ($product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            $inherit = $parent->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $forced = get_option('wc_deposits_storewide_deposit_force_deposit', 'no') === 'yes';
            } else {
                $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

                if ($override) {
                    $forced = $product->get_meta('_wc_deposits_force_deposit', true) === 'yes';
                } else {
                    $forced = $parent->get_meta('_wc_deposits_force_deposit', true) === 'yes';
                }
            }


        } else {

            $inherit = $product->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $forced = get_option('wc_deposits_storewide_deposit_force_deposit', 'no') === 'yes';
            } else {
                $forced = $product->get_meta('_wc_deposits_force_deposit', true) === 'yes';
            }
        }
    }

    return apply_filters('wc_deposits_product_force_deposit', $forced, $product_id);

}

function wc_deposits_get_product_deposit_amount($product_id)
{
    $amount = false;
    $product = wc_get_product($product_id);

    if ($product) {

        // if it is a variation , check variation directly
        if ($product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            $inherit = $parent->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $amount = get_option('wc_deposits_storewide_deposit_amount', '50');
            } else {
                $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

                if ($override) {
                    $amount = $product->get_meta('_wc_deposits_deposit_amount', true);
                } else {
                    $amount = $parent->get_meta('_wc_deposits_deposit_amount', true);
                }
            }


        } else {

            $inherit = $product->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $amount = get_option('wc_deposits_storewide_deposit_amount', '50');
            } else {
                $amount = $product->get_meta('_wc_deposits_deposit_amount', true);
            }
        }
    }


    return apply_filters('wc_deposits_product_deposit_amount', $amount, $product_id);

}

function wc_deposits_get_product_deposit_amount_type($product_id)
{

    $amount_type = false;
    $product = wc_get_product($product_id);


    if ($product) {

        // if it is a variation , check variation directly
        if ($product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            $inherit = $parent->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $amount_type = get_option('wc_deposits_storewide_deposit_amount_type', 'percent');
            } else {

                $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

                if ($override) {
                    $amount_type = $product->get_meta('_wc_deposits_amount_type', true);
                } else {
                    $amount_type = $parent->get_meta('_wc_deposits_amount_type', true);
                }
            }


        } else {

            $inherit = $product->get_meta('_wc_deposits_inherit_storewide_settings');
            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $amount_type = get_option('wc_deposits_storewide_deposit_amount_type', 'percent');
            } else {
                $amount_type = $product->get_meta('_wc_deposits_amount_type', true);
            }
        }
    }
    return apply_filters('wc_deposits_product_deposit_amount_type', $amount_type, $product_id);
}


function wc_deposits_get_product_available_plans($product_id)
{
    $product = wc_get_product($product_id);
    $plans = array();
    if ($product) {

        // if it is a variation , check variation directly
        if ($product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            $inherit = $parent->get_meta('_wc_deposits_inherit_storewide_settings');

            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $plans = get_option('wc_deposits_storewide_deposit_payment_plans', array());
            } else {
                $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';
                if ($override) {
                    $plans = $product->get_meta('_wc_deposits_payment_plans', true);
                } else {
                    $plans = $parent->get_meta('_wc_deposits_payment_plans', true);
                }
            }

        } else {

            $inherit = $product->get_meta('_wc_deposits_inherit_storewide_settings');
            if (empty($inherit) || $inherit === 'yes') {
                // get global setting
                $plans = get_option('wc_deposits_storewide_deposit_payment_plans', array());
            } else {
                $plans = $product->get_meta('_wc_deposits_payment_plans', true);
            }
        }
    }

    return apply_filters('wc_deposits_product_deposit_available_plans', $plans, $product_id);
}

function wc_deposits_delete_current_schedule($order)
{

    $payments = wcdp_get_order_partial_payments($order->get_id());

    // Public API — Automattic\WooCommerce\Internal\* is explicitly not a stable contract.
    $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    if ($hpos_enabled) {
        foreach($payments as $payment){
            $payment->delete();
        }
    } else {
        foreach ($payments as $payment) {
            wp_delete_post(absint($payment->get_id()), true);
        }
    }

    $order->delete_meta_data('_wc_deposits_payment_schedule');
    $order->save();

}


function wc_deposits_create_payment_schedule($order, $sorted_schedule = array())
{

    /**   START BUILD PAYMENT SCHEDULE**/
    try {

        //fix wpml language
        $wpml_lang = $order->get_meta('wpml_language', true);
        $partial_payments_structure = apply_filters('wc_deposits_partial_payments_structure', get_option('wc_deposits_partial_payments_structure', 'single'), 'order');

        foreach ($sorted_schedule as $partial_key => $payment) {

            $partial_payment = new WCDP_Payment();


            //migrate all fields from parent order


            $partial_payment->set_customer_id($order->get_user_id());


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
                Wc_Deposits_Admin_order::create_partial_payment_items($partial_payment, $order, $payment['details']);
                $partial_payment->save();
//                $partial_payment->recalculate_coupons();
                $partial_payment->calculate_totals(false);
                $partial_payment->add_meta_data('_wc_deposits_partial_payment_itemized', 'yes');

            }
            $is_vat_exempt = $order->get_meta('is_vat_exempt', true);

            if (isset($payment['timestamp']) && is_numeric($payment['timestamp'])) {
                $partial_payment->add_meta_data('_wc_deposits_partial_payment_date', $payment['timestamp']);
            }

            if (isset($payment['details'])) {
                $partial_payment->add_meta_data('_wc_deposits_partial_payment_details', $payment['details']);
            }
            $partial_payment->set_parent_id($order->get_id());
            $partial_payment->add_meta_data('is_vat_exempt', $is_vat_exempt);
            $partial_payment->add_meta_data('_wc_deposits_payment_type', $payment['type'], true);
            $partial_payment->set_currency($order->get_currency());
            $partial_payment->set_prices_include_tax($order->get_prices_include_tax());
            $partial_payment->set_customer_ip_address($order->get_customer_ip_address());
            $partial_payment->set_customer_user_agent($order->get_customer_user_agent());

            if ($order->get_status() === 'partially-paid' && $payment['type'] === 'deposit') {

                //we need to save to generate id first
                $partial_payment->set_status('completed');

            }

            if (!empty($wpml_lang)) {
                $partial_payment->update_meta_data('wpml_language', $wpml_lang);
            }


            if (floatval($partial_payment->get_total()) == 0.0) $partial_payment->set_status('completed');

            $partial_payment->save();
            do_action('wc_deposits_partial_payment_created', $partial_payment->get_id(), 'backend');

            $sorted_schedule[$partial_key]['id'] = $partial_payment->get_id();

        }
        return $sorted_schedule;
    } catch (\Exception $e) {
        print_r(new WP_Error('error', $e->getMessage()));
    }

}

function wcdp_get_order_partial_payments($order_id, $args = array(), $object = true)
{
    $default_args = array(
        'parent' => $order_id,
        'type' => 'wcdp_payment',
        'limit' => -1,
        'order' => 'ASC',
        'orderby' => 'date',
        'status' => array_keys(wc_get_order_statuses())
    );

    $args = ($args) ? wp_parse_args($args, $default_args) : $default_args;

    $orders = array();

    //get children of order
    $partial_payments = wc_get_orders($args);
    foreach ($partial_payments as $partial_payment) {
        $orders[] = ($object) ? wc_get_order($partial_payment->get_id()) : $partial_payment->get_id();
    }
    return $orders;
}

/**
 * Order ID currently being edited in wp-admin, for either storage mode.
 *
 * HPOS uses admin.php?page=wc-orders&action=edit&id=123; the legacy post table
 * uses post.php?post=123&action=edit. Reading only `post` silently yielded no
 * order on HPOS screens.
 *
 * @return int Order ID, or 0 when not on an order edit screen.
 */
function wcdp_get_admin_screen_order_id()
{
    if (isset($_GET['id']) && absint($_GET['id'])) {
        return absint($_GET['id']);
    }
    if (isset($_GET['post']) && absint($_GET['post'])) {
        return absint($_GET['post']);
    }
    return 0;
}

/**
 * Count orders of a type by status, correct under both HPOS and the post table.
 *
 * @param string $type Order type.
 * @return array status => count
 */
function wcdp_get_order_counts_for_type($type)
{
    if (is_callable(array('\Automattic\WooCommerce\Utilities\OrderUtil', 'get_count_for_type'))) {
        return (array) \Automattic\WooCommerce\Utilities\OrderUtil::get_count_for_type($type);
    }
    return (array) wp_count_posts($type);
}

add_action('woocommerce_after_dashboard_status_widget', 'wcdp_status_widget_partially_paid');
function wcdp_status_widget_partially_paid()
{
    if (!current_user_can('edit_shop_orders')) {
        return;
    }
    $partially_paid_count = 0;
    foreach (wc_get_order_types('order-count') as $type) {
        // wp_count_posts() reads the post table, which is not authoritative under
        // HPOS — it under-reports (or reports 0) once orders stop being synced.
        $counts = wcdp_get_order_counts_for_type($type);
        $partially_paid_count += isset($counts['wc-partially-paid']) ? $counts['wc-partially-paid'] : 0;
    }

    $list_url = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ? admin_url('admin.php?page=wc-orders&status=wc-partially-paid')
        : admin_url('edit.php?post_status=wc-partially-paid&post_type=shop_order');
    ?>
    <li class="partially-paid-orders">
        <a href="<?php echo esc_url($list_url); ?>">
            <?php
            printf(
                _n('<strong>%s order</strong> partially paid', '<strong>%s orders</strong> partially paid', $partially_paid_count, 'woocommerce-deposits'),
                esc_html($partially_paid_count)
            );
            ?>
        </a>
    </li>
    <style>
        #woocommerce_dashboard_status .wc_status_list li.partially-paid-orders a::before {
            content: '\e011';
            color: #ffba00;
        }
    </style>
    <?php
}

function wc_deposits_remove_order_deposit_data($order)
{

    $order->delete_meta_data('_wc_deposits_order_version');
    $order->delete_meta_data('_wc_deposits_order_has_deposit');
    $order->delete_meta_data('_wc_deposits_deposit_paid');
    $order->delete_meta_data('_wc_deposits_second_payment_paid');
    $order->delete_meta_data('_wc_deposits_deposit_amount');
    $order->delete_meta_data('_wc_deposits_second_payment');
    $order->delete_meta_data('_wc_deposits_deposit_breakdown');
    $order->delete_meta_data('_wc_deposits_deposit_payment_time');
    $order->delete_meta_data('_wc_deposits_second_payment_reminder_email_sent');
    $order->save();

}

function wc_deposits_validate_customer_eligibility($enabled)
{
    //user restriction
    $allow_deposit_for_guests = get_option('wc_deposits_restrict_deposits_for_logged_in_users_only', 'no');

    if ($allow_deposit_for_guests !== 'no' && is_user_logged_in() && isset($_POST['createaccount']) && $_POST['createaccount'] == 1) {
        //account created during checkout
        $enabled = false;

    } elseif (is_user_logged_in()) {

        $disabled_user_roles = get_option('wc_deposits_disable_deposit_for_user_roles', array());
        if (!empty($disabled_user_roles)) {

            foreach ($disabled_user_roles as $disabled_user_role) {

                if (wc_current_user_has_role($disabled_user_role)) {

                    $enabled = false;
                }
            }
        }
    } else {
        if ($allow_deposit_for_guests !== 'no') {
            $enabled = false;
        }
    }

    return $enabled;
}

add_filter('wc_deposits_deposit_enabled_for_customer', 'wc_deposits_validate_customer_eligibility');

function wcdp_valid_parent_statuses_for_partial_payment()
{
    return apply_filters('wc_deposits_valid_parent_statuses_for_partial_payment', array('partially-paid'));
}

function wcdp_partial_payment_complete_order_status()
{
    return apply_filters('wc_deposits_partial_payment_complete_order_status', 'partially-paid');
}

function WCDP()
{
    return \DepositWP\WC_Deposits::get_singleton();
}

add_action('wc_deposits_job_scheduler', 'wcdp_backward_compatibility_cron_trigger');
function wcdp_backward_compatibility_cron_trigger()
{
    do_action('woocommerce_deposits_second_payment_reminder');
}


function wcdp_is_wcdp_payment_screen()
{


    if (!function_exists('get_current_screen')) return false;
    $screen = get_current_screen();
    if(!$screen) return false;

    // Public API — Automattic\WooCommerce\Internal\* is explicitly not a stable contract.
    $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $screen_name = $hpos_enabled && function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('wcdp_payment') : 'wcdp_payment';
    return $screen->id == $screen_name;

}


function wcdp_is_shop_order_screen()
{


    if (!function_exists('get_current_screen')) return false;
    $screen = get_current_screen();
    if(!$screen) return false;

    // Public API — Automattic\WooCommerce\Internal\* is explicitly not a stable contract.
    $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $screen_name = $hpos_enabled && function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
    
    
    return $screen->id == $screen_name;
}

/**
 * @brief returns the frontend colors from the WooCommerce settings page, or the defaults.
 *
 * @return array
 */

 function wc_deposits_woocommerce_frontend_colors()
 {
     $colors = (array)get_option('woocommerce_colors');
     if (empty($colors['primary']))
         $colors['primary'] = '#ad74a2';
     if (empty($colors['secondary']))
         $colors['secondary'] = '#f7f6f7';
     if (empty($colors['highlight']))
         $colors['highlight'] = '#85ad74';
     if (empty($colors['content_bg']))
         $colors['content_bg'] = '#ffffff';
     return $colors;
 }
 

 
function wcdp_get_deposit_slider_custom_styles(){
    // Toggle colors. Emitted ONLY when the merchant has set one, so the defaults live
    // exactly once — as the fallbacks on .wcdp-toggle in style.css (selected #000000,
    // track #5d5b5b, text #fff). This previously fell back to
    // wc_deposits_woocommerce_frontend_colors() and so emitted a value on every render,
    // which made those CSS fallbacks unreachable and put the default in two places.
    // Also (array) cast: the option is false before it is ever saved, and indexing false
    // raised a warning.
    $colors    = (array) get_option('wc_deposits_deposit_buttons_colors', array());
    $sel_bg    = isset($colors['primary']) ? trim((string) $colors['primary']) : '';
    $sel_text  = isset($colors['secondary']) ? trim((string) $colors['secondary']) : '';

    $basic_buttons = get_option('wc_deposits_use_basic_radio_buttons', true) === 'yes';

    // Card, heading, message and radio-option styling all live in assets/css/style.css and
    // read --wcdp-* custom properties, each with the original default as its CSS fallback.
    // So this only publishes the properties a merchant has actually set: a blank option emits
    // nothing and the stylesheet's default applies. Nothing here needs !important, and there
    // is exactly one place a default is written down (the fallback) instead of three.
    //
    // A bare number means px (the Styling tab accepts "16" as well as "16px", "1.5rem",
    // "var(--x)" or "calc(...)"); anything else is passed through verbatim.
    $wcdp_len = static function ($val) {
        return preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $val) ? $val . 'px' : $val;
    };

    $card_vars = array();
    $opt = static function ($key) { return trim((string) get_option($key, '')); };

    if ('' !== ($v = $opt('wc_deposits_style_card_bg')))            { $card_vars[] = "--wcdp-card-bg:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_card_border_color')))  { $card_vars[] = "--wcdp-card-border-color:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_card_border_width')))  { $card_vars[] = "--wcdp-card-border-width:" . $wcdp_len($v); }
    if ('' !== ($v = $opt('wc_deposits_style_card_radius')))        { $card_vars[] = "--wcdp-card-radius:" . $wcdp_len($v); }
    if ('' !== ($v = $opt('wc_deposits_style_card_padding')))       { $card_vars[] = "--wcdp-card-padding:" . $wcdp_len($v); }
    if ('' !== ($v = $opt('wc_deposits_style_heading_bg')))         { $card_vars[] = "--wcdp-heading-bg:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_heading_color')))      { $card_vars[] = "--wcdp-heading-color:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_option_divider')))     { $card_vars[] = "--wcdp-option-divider:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_heading_size')))       { $card_vars[] = "--wcdp-heading-size:" . $wcdp_len($v); }
    if ('' !== ($v = $opt('wc_deposits_style_heading_weight')))     { $card_vars[] = "--wcdp-heading-weight:{$v}"; }

    // Option typography (both styles): the toggle's buttons and the radio rows are the same
    // choice presented differently. Kept under the --wcdp-toggle-* names their option ids
    // use; style.css reads them from both the toggle and the radio selectors.
    if ('' !== ($v = $opt('wc_deposits_style_toggle_label_size'))) { $card_vars[] = "--wcdp-toggle-label-size:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_toggle_price_size'))) { $card_vars[] = "--wcdp-toggle-price-size:{$v}"; }
    if ('' !== ($v = $opt('wc_deposits_style_toggle_weight')))     { $card_vars[] = "--wcdp-toggle-weight:{$v}"; }

    // Header/message alignment applies to both styles (heading in either, message in toggle).
    $t_align = trim((string) get_option('wc_deposits_style_toggle_align', 'left'));
    if ('center' === $t_align || 'right' === $t_align) {
        $card_vars[] = "--wcdp-card-align:{$t_align}";
    }

    // ── Options Style — ONE set of settings drives BOTH the toggle buttons and the radio
    // rows. Each value is published under both the toggle's and the radio's variable names
    // so the existing selectors in style.css pick it up. The pre-merge per-mode options (the
    // deposit_buttons_colors composite from the old Toggle section, and the separate toggle
    // message color) are read as a fallback ONLY for the window between the plugin files
    // updating and the 5.3.1 migration in update_database() running (first wp-admin load):
    // the migration copies those values into the new option ids — where the Styling tab can
    // show and clear them — and deletes the legacy rows, after which these reads find
    // nothing. Without the migration, a legacy color was invisible in the settings UI and
    // a blank save could never clear it. ──
    $selected_bg   = $opt('wc_deposits_style_option_selected_bg');
    if ('' === $selected_bg)   { $selected_bg = $sel_bg; }     // legacy toggle "primary"
    $selected_text = $opt('wc_deposits_style_selected_text');
    if ('' === $selected_text) { $selected_text = $sel_text; } // legacy toggle "secondary"
    $hover_bg      = $opt('wc_deposits_style_option_hover_bg');
    $opt_padding   = $opt('wc_deposits_style_toggle_padding');
    $opt_radius    = $opt('wc_deposits_style_toggle_radius');
    $sec_size      = $opt('wc_deposits_style_toggle_message_size');
    $sec_color     = $opt('wc_deposits_style_subline_color');
    if ('' === $sec_color)     { $sec_color = $opt('wc_deposits_style_toggle_message_color'); } // legacy

    if ('' !== $selected_bg) {
        $card_vars[] = "--wcdp-option-selected-bg:{$selected_bg}";
        $card_vars[] = "--wcdp-toggle-selected:{$selected_bg}";
    }
    if ('' !== $selected_text) {
        $card_vars[] = "--wcdp-option-selected-text:{$selected_text}";
        $card_vars[] = "--wcdp-toggle-text:{$selected_text}";
    }
    // (Option Text Color / --wcdp-option-text was removed when option labels gained auto-contrast: unselected labels now
    //  auto-contrast against their own tint, and the selected label against its fill, so a
    //  single base text color no longer has a meaning that keeps contrast readable.)
    if ('' !== $hover_bg) {
        $card_vars[] = "--wcdp-option-hover-bg:{$hover_bg}";
        $card_vars[] = "--wcdp-toggle-hover-bg:{$hover_bg}";
    }
    if ('' !== $opt_padding) {
        $card_vars[] = "--wcdp-option-padding:{$opt_padding}";
        $card_vars[] = "--wcdp-toggle-padding:{$opt_padding}";
    }
    if ('' !== $opt_radius) {
        $card_vars[] = "--wcdp-toggle-radius:{$opt_radius}";
    }
    if ('' !== $sec_size) {
        $card_vars[] = "--wcdp-message-size:{$sec_size}";
    }
    if ('' !== $sec_color) {
        // A configured secondary color is shown as chosen, not faded to 70% of itself.
        $card_vars[] = "--wcdp-message-color:{$sec_color}";
        $card_vars[] = "--wcdp-subline-color:{$sec_color}";
        $card_vars[] = "--wcdp-subline-opacity:1";
    }

    // One block. The card wraps both the radio options and the toggle in every PHP template
    // (product page and checkout-mode slider), so its custom properties cascade to both.
    $styles = '';
    if (!empty($card_vars)) {
        $styles .= ".wc-deposits-reserve-card{" . implode(';', $card_vars) . ";}\n";
    }

    return apply_filters('wc_deposits_deposit_slider_custom_styles', $styles);

}
/**
 * The deposit due NOW for the current cart, or null when the cart holds no deposit.
 *
 * Shared by the gateway compatibility shims (official PPCP, Payment Plugins PayPal):
 * both need the same answer while pricing a PayPal order from the CART, inside the
 * gateway's own wc-ajax request where woocommerce_after_calculate_totals has not
 * fired — so deposit_info is populated on demand with the plugin's own calculator
 * rather than depending on hook order. Cheap and idempotent: it only reads the cart
 * and fills deposit_info.
 *
 * @return float|null Deposit amount, or null when this is not a deposit cart.
 */
function wcdp_cart_deposit_amount()
{
    if (!class_exists('\DepositWP\WC_Deposits_Cart') || !function_exists('WC') || !WC()->cart) {
        return null;
    }

    if (!\DepositWP\WC_Deposits_Cart::is_deposit_in_cart()) {
        return null;
    }

    $info = WC()->cart->deposit_info;

    if (!is_array($info) || !isset($info['deposit_amount'])) {
        $wcdp = function_exists('WCDP') ? WCDP() : null;
        if ($wcdp && isset($wcdp->cart) && method_exists($wcdp->cart, 'calculate_deposit_totals')) {
            $wcdp->cart->calculate_deposit_totals(WC()->cart);
            $info = WC()->cart->deposit_info;
        }
    }

    if (!is_array($info) || !isset($info['deposit_amount'])) {
        return null;
    }

    return (float) $info['deposit_amount'];
}
