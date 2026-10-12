<?php


/**
 * Plugin Name: HW Puppy Deposits
 * Plugin URI: https://github.com/Waldoweb/hw-puppy-deposits
 * Description: Deposits & partial payments for WooCommerce, tailored for puppy reservations.
 * Version: 5.4.1
 * Author: Hostetler Web
 * Author URI: https://hostetlerweb.com
 * Update URI: https://github.com/Waldoweb/hw-puppy-deposits
 * Text Domain: woocommerce-deposits
 * Domain Path: /locale
 * Requires at least: 6.0
 * WC requires at least: 8.0.0
 * WC tested up to: 9.9.0
 * Requires Plugins: woocommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace DepositWP;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use stdClass;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updates come from GitHub releases through Plugin Update Checker. Pushing a vX.Y.Z tag
 * builds the release zip (.github/workflows/release.yml); sites check every 6 hours and
 * install it automatically. Registered before the WooCommerce check so a site can still
 * update with WooCommerce off. The Update URI header keeps WordPress.org from offering
 * its same-named woocommerce-deposits plugin as an update.
 */
if (is_readable(__DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php')) {
    require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Waldoweb/hw-puppy-deposits/',
        __FILE__,
        'puppy-deposits',
        6
    )->getVcsApi()->enableReleaseAssets('/\.zip($|[?&#])/i');

    add_filter('auto_update_plugin', function ($update, $item) {
        return (isset($item->plugin) && plugin_basename(__FILE__) === $item->plugin) ? true : $update;
    }, 10, 2);
}


/**
 * Check if WooCommerce is active
 */
function wc_deposits_woocommerce_is_active()
{
    if (!function_exists('is_plugin_active_for_network'))
        require_once(ABSPATH . '/wp-admin/includes/plugin.php');
    // Check if WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        return is_plugin_active_for_network('woocommerce/woocommerce.php');
    }
    return true;
}

if (wc_deposits_woocommerce_is_active()):
    require_once('includes/wc-deposits-functions.php');


    /**
     *  Main WC_Deposits class
     *
     */
    class WC_Deposits
    {
        /**
         * @var WC_Deposits_Cart
         */
        public $cart;
        /**
         * @var WC_Deposits_Coupons
         */
        public $coupons;
        /**
         * @var WC_Deposits_Add_To_Cart
         */
        public $add_to_cart;
        /**
         * @var WC_Deposits_Orders
         */
        public $orders;
        /**
         * @var WC_Deposits_Taxonomies
         */
        public $taxonomies;
        /**
         * @var WC_Deposits_Reminders
         */
        public $reminders;
        /**
         * @var WC_Deposits_Emails
         */
        public $emails;
        /**
         * @var WC_Deposits_Checkout
         */
        public $checkout;
        /**
         * @var stdClass
         * @description  all compatibility classes are loaded in this var
         */
        public $compatibility;
        /**
         * @var WC_Deposits_Admin_Product
         */
        public $admin_product;
        /**
         * @var WC_Deposits_Admin_Order
         */
        public $admin_order;
        /**
         * @var WC_Deposits_Admin_List_Table_Orders
         */
        public $admin_list_table_orders;
        /**
         * @var WC_Deposits_Admin_List_Table_Partial_Payments
         */
        public $admin_list_table_partial_payments;
        /**
         * @var WC_Deposits_Admin_Settings
         */
        public $admin_settings;
        /**
         * @description notices are enqueued in this array before output function
         * @var array
         */
        public $admin_notices = array();
        /**
         * @description stores version disabled state
         * @var bool
         */
        public $wc_version_disabled = false;
        /**
         * @var WC_Deposits_Rest_Api
         */
        public $rest_api;
        /**
         *  Returns the global instance
         *
         * @param array $GLOBALS ...
         * @return mixed
         */
        public static function &get_singleton()
        {
            if (!isset($GLOBALS['wc_deposits']))
                $GLOBALS['wc_deposits'] = new WC_Deposits();
            return $GLOBALS['wc_deposits'];
        }

        /**
         *  Constructor
         *
         * @return void
         */
        private function __construct()
        {



            // KEEP IN SYNC WITH THE "Version:" PLUGIN HEADER ABOVE. This constant is the
            // cache-buster on every enqueued script/style, so leaving it behind the header
            // means browsers keep serving stale JS/CSS after an update. It had been stuck at
            // the upstream 4.6.10 since the fork. (DB migrations use the separate
            // wc_deposits_db_version option, so bumping this triggers nothing.)
            define('WC_DEPOSITS_VERSION', '5.4.1');
            define('WC_DEPOSITS_DB_VERSION', '5.3.1'); // bump when a new update_database() migration is added
            define('WC_DEPOSITS_TEMPLATE_PATH', untrailingslashit(plugin_dir_path(__FILE__)) . '/templates/');
            define('WC_DEPOSITS_PLUGIN_PATH', plugin_dir_path(__FILE__));
            define('WC_DEPOSITS_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
            define('WC_DEPOSITS_MAIN_FILE', __FILE__);
            define('WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY', 'wcdp_payment_plan');

            $this->compatibility = new stdClass();

            if (version_compare(PHP_VERSION, '7.0.0', '<')) {


                if (is_admin()) {
                    add_action('admin_notices', array($this, 'show_admin_notices'));
                    $this->enqueue_admin_notice(sprintf(esc_html__('%s Requires PHP version %s or higher.'), esc_html__('WooCommerce Deposits', 'woocommerce-deposits'), '5.6'), 'error');
                }

                return;

            }


            add_action('init', array($this, 'load_plugin_textdomain'), 0);
            add_action('init', array($this, 'check_version_disable'), 0);
            add_action('init', array($this, 'register_order_status'));
            add_action('init', array($this, 'register_wcdp_payment_post_type'), 6);

            if (!did_action('woocommerce_init')) {
                add_action('woocommerce_init', array($this, 'early_includes'));
                add_action('woocommerce_init', array($this, 'admin_includes'));
                add_action('woocommerce_init', array($this, 'includes'));
            }

            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts_and_styles'));

            add_action('enqueue_block_assets', array($this, 'enqueue_styles'), 100);
            add_action('wp_enqueue_scripts', array($this, 'register_styles'), 5);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));

            if (is_admin()) {

                //plugin row urls in plugins page
                add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));

                add_action('admin_notices', array($this, 'show_admin_notices'));

                add_action('current_screen', array($this, 'setup_screen'), 0);
                add_action('init', array($this, 'update_database'), 100);

                add_action('wc_deposits_database_update', array($this, 'process_update'));
                add_action('init', 'DepositWP\WC_Deposits::plugin_activated', 100); //plugin activated is not called with automatic updates anymore.

            }
            add_action('before_woocommerce_init', array($this, 'before_woocommerce_init'));




            if (file_exists(WC_DEPOSITS_PLUGIN_PATH . 'includes/blocks/main.php'))
                require_once('includes/blocks/main.php');


        }


        /**
         * Declare compatibility with Custom order tables
         * @return void
         */
        function before_woocommerce_init()
        {


            FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);

        }

        /**
         * Background update process for version 4.0.0 updating
         * @return void
         */
        function process_update()
        {

            if (version_compare(get_option('wc_deposits_db_version', '3.0.0'), '4.0.0', '<')) {
                //4.0 UPDATE
                //update the override value of products
                $args = array(
                    'post_type' => 'product',
                    'fields' => 'ids',
                    'posts_per_page' => 50, //limit to 50 per update
                    'meta_query' => array(
                        'has_deposit' => array(
                            'key' => '_wc_deposits_enable_deposit',
                            'compare' => 'EXISTS',
                        ),
                        'inherit' => array(
                            'key' => '_wc_deposits_inherit_storewide_settings',
                            'compare' => 'NOT EXISTS',
                        ),
                    )
                );


                //query for all partially-paid orders
                $deposit_products = new \WP_Query($args);
                if ($deposit_products->post_count == 0) {
                    WC()->queue()->cancel_all('wc_deposits_database_update');
                    update_option('wc_deposits_db_version', '4.0.0');
                    $this->enqueue_admin_notice(esc_html__('WooCommerce Deposits database update complete', 'woocommerce-deposits'), 'info', true);

                    return;
                }
                $this->enqueue_admin_notice(esc_html__('WooCommerce Deposits database update running in the background', 'woocommerce-deposits'), 'info', true);

                while ($deposit_products->have_posts()):

                    $deposit_products->the_post();
                    $product_id = $deposit_products->post;
                    $product = wc_get_product($product_id);
                    $product->update_meta_data('_wc_deposits_inherit_storewide_settings', 'no');
                    $product->save();
                endwhile;
            }


        }

        /**
         * Checks installed WC version against minimum version required
         * @return void
         */
        function check_version_disable()
        {
            if (function_exists('WC') && version_compare(WC()->version, '3.7.0', '<')) {

                $this->wc_version_disabled = true;

                if (is_admin()) {
                    add_action('admin_notices', array($this, 'show_admin_notices'));
                    $this->enqueue_admin_notice(sprintf(esc_html__('%s Requires WooCommerce version %s or higher.'), esc_html__('WooCommerce Deposits', 'woocommerce-deposits'), '3.7.0'), 'error');
                }
            }

        }

        /**
         * Register wcdp_payment custom order type
         * @return void
         */
        function register_wcdp_payment_post_type()
        {

            if ($this->wc_version_disabled || !function_exists('wc_register_order_type'))
                return;
            wc_register_order_type(
                'wcdp_payment',

                array(
                    // register_post_type() params
                    'labels' => array(
                        'name' => esc_html__('Partial Payments', 'woocommerce-deposits'),
                        'singular_name' => esc_html__('Partial Payment', 'woocommerce-deposits'),
                        'edit_item' => esc_html_x('Edit Partial Payment', 'custom post type setting', 'woocommerce-deposits'),
                        'search_items' => esc_html__('Search Partial Payments', 'woocommerce-deposits'),
                        'parent' => esc_html_x('Order', 'custom post type setting', 'woocommerce-deposits'),
                        'menu_name' => esc_html__('Partial Payments', 'woocommerce-deposits'),
                    ),
                    'public' => false,
                    'show_ui' => true,
                    'capability_type' => 'shop_order',
                    'capabilities' => array(
                        'create_posts' => 'do_not_allow',
                    ),
                    'map_meta_cap' => true,
                    'publicly_queryable' => false,
                    'exclude_from_search' => true,
                    'show_in_menu' => 'woocommerce',
                    'hierarchical' => false,
                    'show_in_nav_menus' => false,
                    'rewrite' => false,
                    'query_var' => false,
                    'supports' => array('title', 'comments', 'custom-fields'),
                    'has_archive' => false,

                    // wc_register_order_type() params
                    'exclude_from_orders_screen' => true,
                    'add_order_meta_boxes' => true,
                    'exclude_from_order_count' => true,
                    'exclude_from_order_views' => true,
                    'exclude_from_order_webhooks' => true,
                    'class_name' => 'WCDP_Payment',
                )

            );
        }

        /**
         *  Add a Settings link to the plugin's action links on the plugins page.
         *
         * Uses plugin_action_links_{basename} (the left column, alongside Deactivate)
         * rather than plugin_row_meta (the right column, after the author). unshift puts
         * it ahead of Deactivate. Upstream's Documentation / Support / View details meta
         * links all pointed at Webtomizer's own site and were dropped with the fork, so
         * the row-meta callback had nothing else left in it.
         *
         * @param $links
         * @return array|mixed
         */
        function plugin_action_links($links)
        {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=wc-deposits')) . '">' . esc_html__('Settings', 'woocommerce-deposits') . '</a>');

            return $links;
        }

        /**
         *   load plugin's translated strings
         * @brief Localisation
         *
         * @return void
         */
        public function load_plugin_textdomain()
        {


            load_plugin_textdomain('woocommerce-deposits', false, dirname(plugin_basename(__FILE__)) . '/locale/');
        }


        public function register_styles()
        {

            wp_register_style('wc-deposits-frontend-styles', plugins_url('assets/css/style.css', __FILE__), [], WC_DEPOSITS_VERSION);

        }
        /**
         *  Enqueues front-end styles
         *
         * @return void
         */

        public function enqueue_styles()
        {


            if (!function_exists('WC'))
                return;
            if ($this->wc_version_disabled)
                return;
            if (!$this->is_disabled()) {

                //blocks assets are loading by blocks class
                if (has_block('woocommerce/checkout')) {
                    return;
                }


                wp_enqueue_style('wc-deposits-frontend-styles');
                // This method runs on BOTH enqueue_block_assets and wp_enqueue_scripts, both
                // of which fire on a front-end page — guard so the inline CSS is only appended
                // once per request (otherwise the whole block is duplicated in <head>).
                static $wcdp_inline_styles_added = false;
                if (!$wcdp_inline_styles_added) {
                    $wcdp_inline_styles_added = true;
                    wp_add_inline_style('wc-deposits-frontend-styles', wcdp_get_deposit_slider_custom_styles());
                }


                if (is_cart() || is_checkout()) {


                    if (get_option('wc_deposits_breakdown_cart_tooltip', 'no') === 'yes') {
                        wp_register_script('jquery-tiptip', WC()->plugin_url() . '/assets/js/jquery-tiptip/jquery.tipTip.min.js', array('jquery'), WC()->version, true);
                        wp_enqueue_script('jquery-tiptip');

                        $script = '
                            jQuery(document).ready(function ($) {
                            "use strict";
            
                            if ($("#deposit-help-tip").length === 0) {
                                return;
                            }
            
                            var activate_tooltip = function () {
                                $("#deposit-help-tip").tipTip({
                                attribute: "data-tip",
                                fadeIn: 50,
                                fadeOut: 50,
                                delay: 200,
                                });
                            };
            
                            $(document.body).on("updated_cart_totals updated_checkout", activate_tooltip);
            
                            activate_tooltip();
                            });
                        ';


                        wp_add_inline_script('jquery-tiptip', $script);


                    }



                    wp_register_script('wc-deposits-checkout', WC_DEPOSITS_PLUGIN_URL . '/assets/js/wc-deposits-checkout.js', array('jquery', 'wc-checkout'), WC_DEPOSITS_VERSION, true);
                }
            }
        }

        /**
         *  Early includes
         *
         * @return void
         * @since 1.3
         *
         */

        public function early_includes()
        {
            if ($this->wc_version_disabled)
                return;

            require_once 'includes/admin/class-wc-deposits-rest-api.php';
            $this->rest_api = new WC_Deposits_Rest_Api();
            require_once 'includes/class-wc-deposits-emails.php';
            $this->emails = WC_Deposits_Emails::instance();

            require_once 'includes/class-wc-deposits-reminders.php';
            $this->reminders = new WC_Deposits_Reminders();


        }

        /**
         *  Load classes
         *
         * @return void
         */
        public function includes()
        {

            if ($this->wc_version_disabled)
                return;
            if (!$this->is_disabled()) {

                require_once('includes/class-wc-deposits-cart.php');
                require_once('includes/class-wc-deposits-coupons.php');
                require_once('includes/class-wc-deposits-checkout.php');


                $this->cart = new WC_Deposits_Cart();
                $this->checkout = new WC_Deposits_Checkout();
                $this->coupons = new WC_Deposits_Coupons();



                if (!wcdp_checkout_mode()) {
                    require_once('includes/class-wc-deposits-add-to-cart.php');
                    $this->add_to_cart = new WC_Deposits_Add_To_Cart();

                }



            }

            require_once('includes/admin/class-wc-deposits-taxonomies.php');
            require_once('includes/class-wcdp-payment.php');
            require_once('includes/class-wc-deposits-orders.php');
            $this->orders = new WC_Deposits_Orders();
            $this->taxonomies = new WC_Deposits_Taxonomies();


            /**
             * 3RD PARTY COMPATIBILITY
             */

            if (is_plugin_active('woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php')) {
                $this->compatibility->pdf_invoices = require_once('includes/compatibility/pdf-invoices/main.php');
            }

            /**
             * Gateway-agnostic express / wallet button guard.
             * Hides Apple Pay / Google Pay / PayPal smart buttons (official PPCP,
             * Payment Plugins PayPal, Payment Plugins Stripe) when a deposit is in
             * the cart, so express checkout can't bypass order substitution and
             * charge the full amount. Loaded unconditionally — its filters no-op
             * when a given gateway is absent. Replaces the old wc-ppcp/wc-ppec shims.
             */
            $this->compatibility->express_guard = require_once('includes/compatibility/class-puppy-deposits-express-guard.php');

            /**
             * Official PPCP prices its PayPal order from the CART, which knows nothing
             * about the deposit — so PayPal was quoted the full total and the later
             * amount patch was rejected (422), failing the payment. Surfaces the
             * not-yet-due balance through PPCP's own cart_extra_discount hook so the
             * PayPal order is created at the deposit amount. No-ops when PPCP is absent.
             */
            $this->compatibility->ppcp_deposit_amount = require_once('includes/compatibility/class-ppcp-deposit-amount.php');

            /**
             * Payment Plugins for PayPal has the same cart-priced PayPal order problem
             * as official PPCP (shopper approves the full total, the pre-capture amount
             * patch is then rejected). Reprices the purchase unit to the deposit through
             * that gateway's own from_cart filter. No-ops when the gateway is absent.
             */
            $this->compatibility->pymntpl_paypal_deposit_amount = require_once('includes/compatibility/class-pymntpl-paypal-deposit-amount.php');

        }


        /**
         *  load proper admin list table class based on current screen
         * @return void
         */
        function setup_screen()
        {

            if ($this->wc_version_disabled)
                return;

            $screen_id = false;

            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                $screen_id = isset($screen, $screen->id) ? $screen->id : '';
            }

            if (!empty($_REQUEST['screen'])) { // WPCS: input var ok.
                $screen_id = wc_clean(wp_unslash($_REQUEST['screen'])); // WPCS: input var ok, sanitization ok.
            }

            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {


                $order_screen_id = OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id('shop-order') : 'edit-shop_order';
                $partial_payment_screen_id = OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id('wcdp_payment') : 'edit-wcdp_payment';

            }


            if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wc-orders--wcdp_payment') {
                require_once('includes/admin/list-tables/class-wc-deposits-wcdp-payment-hpos-table.php');
            }

            switch ($screen_id) {
                case $order_screen_id:

                    require_once('includes/admin/list-tables/class-wc-deposits-admin-list-table-orders.php');
                    $this->admin_list_table_orders = new WC_Deposits_Admin_List_Table_Orders();
                    break;
                case $partial_payment_screen_id:
                    require_once('includes/admin/list-tables/class-wc-deposits-admin-list-table-partial-payments.php');
                    $this->admin_list_table_partial_payments = new WC_Deposits_Admin_List_Table_Partial_Payments();
                    break;

            }



        }

        /**
         *  Load admin includes
         *
         * @return void
         */
        public function admin_includes()
        {
            if ($this->wc_version_disabled)
                return;

            require_once('includes/admin/class-wc-deposits-admin-settings.php');
            require_once('includes/admin/class-wc-deposits-admin-order.php');

            $this->admin_settings = new WC_Deposits_Admin_Settings($this);
            $this->admin_order = new WC_Deposits_Admin_Order($this);

            require_once('includes/admin/class-wc-deposits-admin-product.php');
            $this->admin_product = new WC_Deposits_Admin_Product($this);
        }

        /**
         *  Load admin scripts and styles
         * @return void
         */
        public function enqueue_admin_scripts_and_styles()
        {
            wp_enqueue_script('jquery');
            // WC_DEPOSITS_VERSION was being passed as the THIRD argument, which is $deps —
            // so $ver stayed false and WordPress used ITS OWN version as the cache-buster
            // (?ver=7.0.1). Admin CSS changes therefore did not reach browsers until
            // WordPress itself updated. Third argument is deps (none), fourth is the version.
            wp_enqueue_style('wc-deposits-admin-style', plugins_url('assets/css/admin-style.css', __FILE__), array(), WC_DEPOSITS_VERSION);
        }

        /**
         *  Display all buffered admin notices
         *
         * @return void
         */
        public function show_admin_notices()
        {
            foreach ($this->admin_notices as $notice) {
                $dismissible = isset($notice['dismissible']) && $notice['dismissible'] ? 'is-dismissible' : '';
                ?>
                <div class='<?php echo $dismissible; ?> notice notice-<?php echo esc_attr($notice['type']); ?>'>
                    <p><?php echo $notice['content']; ?></p>
                </div>
                <?php
            }
        }

        /**
         *  Add a new notice
         *
         * @param $content String notice contents
         * @param $type String Notice class
         *
         * @return void
         */
        public function enqueue_admin_notice($content, $type, $dismissible = false)
        {
            array_push($this->admin_notices, array('content' => $content, 'type' => $type, 'dismissible' => $dismissible));
        }

        /**
         *  checks if plugin frontend functionality is disabled sitewide
         * @return bool
         */
        public function is_disabled()
        {
            return get_option('wc_deposits_site_wide_disable') === 'yes';
        }

        /**
         *  update database from older versions
         * @return void
         * @throws \WC_Data_Exception
         */
        public function update_database()
        {

            if (!is_admin())
                return;

            // Once the DB is current there is nothing to migrate. Bail before doing any
            // work — the tail of this method otherwise ran WC()->queue()->cancel_all()
            // (an Action Scheduler DB query) on EVERY admin page load, forever.
            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), WC_DEPOSITS_DB_VERSION, '>=')) {
                return;
            }


            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '2.4.0', '<')) {


                //2.4 UPDATE REQUIRED

                //save gateways to new multiselect fields
                $deprecated_gateways_option = get_option('wc_deposits_disabled_gateways', array());
                if (!empty($deprecated_gateways_option)) {
                    $selected_gateways = array();
                    foreach ($deprecated_gateways_option as $key => $value) {

                        if ($value === 'yes') {
                            $selected_gateways[] = $key;
                        }

                    }

                    update_option('wc_deposits_disallowed_gateways_for_deposit', $selected_gateways);
                }

                update_option('wc_deposits_db_version', '2.4.0');

            }

            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '2.5.0', '<')) {

                set_time_limit(600);

                //2.5.0 UPDATE REQUIRED

                //remove deprecated option
                delete_option('wc_deposits_enable_product_calculation_filter');


                //query for any order with deposit meta enabled


                $statuses = array_keys(wc_get_order_statuses());
                if (isset($statuses['wc-completed']))
                    unset($statuses['wc-completed']);


                $args = array(
                    'post_type' => 'shop_order',
                    'posts_per_page' => -1,
                    'post_status' => $statuses,
                    'meta_query' => array(
                        'has_deposit' => array(
                            'key' => '_wc_deposits_order_has_deposit',
                            'value' => "yes",
                            'compare' => '=',
                        ),
                        // no need to compare number because order version meta does not exist before this patch
                        array(
                            'key' => '_wc_deposits_order_version',
                            'compare' => 'NOT EXISTS',
                        ),

                    )
                );


                //query for all partially-paid orders
                $deposit_orders = new \WP_Query($args);

                while ($deposit_orders->have_posts()):

                    $deposit_orders->the_post();
                    $order_id = $deposit_orders->post->ID;


                    $order = wc_get_order($order_id);

                    if (!$order)
                        continue;
                    $deposit_amount = floatval($order->get_meta('_wc_deposits_deposit_amount', true));
                    $second_payment = floatval($order->get_meta('_wc_deposits_second_payment', true));


                    switch ($order->get_status()) {


                        case 'completed':
                        case 'trash':

                            break;

                        case 'processing':
                            $original_total = $order->get_meta('_wc_deposits_original_total', true);
                            if (is_numeric($original_total)) {

                                $order->set_total(floatval($original_total));
                                $order->save();
                            }

                            break;

                        default:

                            $order->set_total(floatval($deposit_amount + $second_payment));
                            $order->save();
                            $payment_schedule = wc_deposits_create_payment_schedule($order, $deposit_amount);

                            if ($order->get_meta('_wc_deposits_second_payment_paid', true) === 'yes') {
                                foreach ($payment_schedule as $payment) {


                                    $payment_order = wc_get_order($payment['id']);

                                    if ($payment_order) {
                                        $payment_order->set_status('completed');
                                        $payment_order->save();
                                    }

                                }

                            } elseif ($order->get_meta('_wc_deposits_deposit_paid', true) === 'yes') {

                                foreach ($payment_schedule as $payment) {

                                    if ($payment['type'] === 'deposit') {

                                        $payment_order = wc_get_order($payment['id']);

                                        if ($payment_order) {
                                            $payment_order->set_status('completed');
                                            $payment_order->save();
                                        }
                                    }
                                }

                            }


                            $order->save();

                            $order->add_meta_data('_wc_deposits_payment_schedule', $payment_schedule, true);

                            $order->update_meta_data('_wc_deposits_order_version', '2.5.0');

                            $order->save();
                            break;
                    }


                endwhile;

                update_option('wc_deposits_db_version', '2.5.0');
            }

            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '3.0.0', '<')) {

                delete_option('wc_deposits_payment_status_text');
                delete_option('wc_deposits_deposit_pending_payment_text');
                delete_option('wc_deposits_deposit_paid_text');
                delete_option('wc_deposits_order_fully_paid_text');
                delete_option('wc_deposits_deposit_previously_paid_text');
                delete_option('wc_deposits_second_payment_amount_text');
                update_option('wc_deposits_db_version', '3.0.0');
            }


            if (version_compare(get_option('wc_deposits_db_version', '3.0.0'), '4.0.0', '<')) {

                delete_option('wc_deposits_shipping_taxes_handling');

                $next = WC()->queue()->get_next('wc_deposits_database_update');
                if (!$next) {
                    $timestamp = time() + MINUTE_IN_SECONDS;
                    WC()->queue()->cancel_all('wc_deposits_database_update');
                    WC()->queue()->schedule_recurring($timestamp, MINUTE_IN_SECONDS, 'wc_deposits_database_update', array(), 'WCDP');
                }
            } else {
                WC()->queue()->cancel_all('wc_deposits_database_update');
            }

            // 5.3.1 — Styling-merge follow-up. The pre-merge color options were left in
            // the DB as invisible emitter fallbacks, so a store fresh off an update could
            // show a color on the front end while every Styling field sat blank — and
            // saving the blank field could not clear it, because blank re-arms the
            // fallback. Copy each still-active legacy value into the option id its field
            // actually edits (visible, editable, clearable), then delete the legacy rows
            // so a blank field genuinely means "use the default". Own flag rather than
            // wc_deposits_db_version, which the background 4.0.0 product update owns.
            if ('yes' !== get_option('wc_deposits_style_options_migrated')) {
                $legacy_toggle = (array) get_option('wc_deposits_deposit_buttons_colors', array());
                $legacy_values = array(
                    'wc_deposits_style_option_selected_bg' => isset($legacy_toggle['primary']) ? trim((string) $legacy_toggle['primary']) : '',
                    'wc_deposits_style_selected_text'      => isset($legacy_toggle['secondary']) ? trim((string) $legacy_toggle['secondary']) : '',
                    'wc_deposits_style_subline_color'      => trim((string) get_option('wc_deposits_style_toggle_message_color', '')),
                );
                foreach ($legacy_values as $option_id => $legacy_value) {
                    if ('' !== $legacy_value && '' === trim((string) get_option($option_id, ''))) {
                        update_option($option_id, $legacy_value);
                    }
                }
                delete_option('wc_deposits_deposit_buttons_colors');
                delete_option('wc_deposits_style_toggle_message_color');
                delete_option('wc_deposits_style_radio_accent');
                update_option('wc_deposits_style_options_migrated', 'yes');
            }

            // The 4.0.0 product update above completes in the background and owns the
            // advance to '4.0.0'; only move the version past it once it has finished.
            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '4.0.0', '>=')) {
                update_option('wc_deposits_db_version', '5.3.1');
            }

        }

        /**
         *  Register custom order status partially-paid
         *
         * @return void
         * @since 1.3
         *
         */
        public function register_order_status()
        {

            register_post_status('wc-partially-paid', array(
                'label' => _x('Partially Paid', 'Order status', 'woocommerce-deposits'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop(
                    'Partially Paid <span class="count">(%s)</span>',
                    'Partially Paid <span class="count">(%s)</span>',
                    'woocommerce-deposits'
                )
            ));

            // Custom "Fully Paid" status: the full balance has been paid, but the
            // order is not yet Completed/fulfilled. Kept separate from native
            // Completed so the merchant can still use Completed to mean "delivered".
            register_post_status('wc-fully-paid', array(
                'label' => _x('Fully Paid', 'Order status', 'woocommerce-deposits'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop(
                    'Fully Paid <span class="count">(%s)</span>',
                    'Fully Paid <span class="count">(%s)</span>',
                    'woocommerce-deposits'
                )
            ));

        }


        /**
         *  plugin activation hook , schedule action 'wc_deposits_job_scheduler'
         * @return void
         */
        public static function plugin_activated()
        {
            if (!function_exists('WC')) {
                return;
            }

            // Hooked to admin `init`, so this fires on every wp-admin request. Throttle
            // the Action Scheduler lookup to once per day (it still self-heals if the
            // recurring job ever disappears) instead of querying on every page load.
            if (get_transient('wc_deposits_job_scheduler_checked')) {
                return;
            }
            set_transient('wc_deposits_job_scheduler_checked', 1, DAY_IN_SECONDS);

            $next = WC()->queue()->get_next('wc_deposits_job_scheduler');
            if (!$next) {
                $timestamp = time() + DAY_IN_SECONDS;
                WC()->queue()->cancel_all('wc_deposits_job_scheduler');
                WC()->queue()->schedule_recurring($timestamp, DAY_IN_SECONDS, 'wc_deposits_job_scheduler', array(), 'WCDP');
            }

        }

        /**
         *  plugin deactivation hook , remove scheduled action 'wc_deposits_job_scheduler'
         * @return void
         */
        public static function plugin_deactivated()
        {
            if (function_exists('WC')) {

                WC()->queue()->cancel_all('wc_deposits_job_scheduler');
            }

            wp_clear_scheduled_hook('woocommerce_deposits_second_payment_reminder');
            delete_option('wc_deposits_instance');

        }

    }

    // Install the singleton instance
    WC_Deposits::get_singleton();

    register_activation_hook(__FILE__, array('\DepositWP\WC_Deposits', 'plugin_activated'));
    register_deactivation_hook(__FILE__, array('\DepositWP\WC_Deposits', 'plugin_deactivated'));

endif;

