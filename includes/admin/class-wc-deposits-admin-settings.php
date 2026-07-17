<?php

namespace DepositWP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @brief Adds a new panel to the WooCommerce Settings
 *
 */
class WC_Deposits_Admin_Settings
{

    public function __construct()
    {


        $allowed_html = array(
            'a' => array('href' => array(), 'title' => array()),
            'br' => array(), 'em' => array(),
            'strong' => array(), 'p' => array(),
            's' => array(), 'strike' => array(),
            'del' => array(), 'u' => array(), 'b' => array()
        );


        // Hook the settings page
        add_filter('woocommerce_settings_tabs_array', array($this, 'settings_tabs_array'), 21);
        add_action('woocommerce_settings_wc-deposits', array($this, 'settings_tabs_wc_deposits'));
        add_action('woocommerce_update_options_wc-deposits', array($this, 'update_options_wc_deposits'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_settings_script'));

        add_action('woocommerce_admin_field_deposit_buttons_color', array($this, 'deposit_buttons_color'));
        // reminder datepicker
        add_action('woocommerce_admin_field_reminder_datepicker', array($this, 'reminder_datepicker'));
    }


    public function enqueue_settings_script()
    {

        if (function_exists('get_current_screen')) {

            if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'wc-deposits') {

                wp_enqueue_style('wp-color-picker');
                wp_enqueue_script('jquery-ui-datepicker');

                // Grayed-out state for the General tab when deposits are disabled. Piggy-backs
                // on the color-picker stylesheet — always enqueued on this screen — rather than
                // registering a whole stylesheet for two rules.
                // Deliberately NOT the disabled attribute: disabled inputs are not POSTed, and
                // update_options_wc_deposits() reads checkboxes as isset($_POST[...]) ? 'yes' :
                // 'no', so disabling them would silently reset every one to "no" on save.
                wp_add_inline_style('wp-color-picker', '.wcdp-settings-off{opacity:.45;pointer-events:none;}');

                wp_enqueue_script('wc-deposits-admin-settings', WC_DEPOSITS_PLUGIN_URL . '/assets/js/admin/admin-settings.js', array('jquery', 'wp-color-picker'), WC_DEPOSITS_VERSION);
                wp_localize_script('wc-deposits-admin-settings', 'wc_deposits', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'strings' => array(
                        'success' => __('Updated successfully', 'woocommerce-deposits')
                    )

                ));

                $inline_js = <<<'JS'
( function ( $ ) {
    // Only show the "Pay in Full" Unavailable Message when Force deposit by default = Yes.
    // Cross-tab: the select is on General Settings, the message field on Labels & Text.
    // This works because every tab renders its fields into the DOM and inactive tabs are
    // merely CSS-hidden (see tab_*_output) — so both elements are always present.
    function wcdpSyncForceMessage() {
        var $sel = $( '#wc_deposits_storewide_deposit_force_deposit' );
        if ( ! $sel.length ) { return; }
        $( '#wc_deposits_full_unavailable_text' ).closest( 'tr' ).toggle( 'yes' === $sel.val() );
    }
    // Gray out the settings that stop having any effect when deposits are disabled, so the
    // off state is obvious. Cross-tab: every tab is in the DOM, inactive ones merely hidden.
    // Rows keep their name/value so they still POST — see the note on .wcdp-settings-off.
    //
    // Left live on purpose:
    //   - the master select itself and its "Global Settings" heading;
    //   - #second_payment — emails and reminders load in early_includes(), OUTSIDE the
    //     is_disabled() guard, so existing partially-paid orders keep sending reminders
    //     and keep their pay links. Graying it would claim otherwise.
    function wcdpSyncDepositsEnabled() {
        var $sel = $( '#wc_deposits_site_wide_disable' );
        if ( ! $sel.length ) { return; }
        var off = 'yes' === $sel.val();
        var $masterRow = $sel.closest( 'tr' );
        var $masterHeading = $( '#wcdp_general' ).find( 'h2' ).first();
        // Badge lives above the tab nav, so it covers every tab.
        $( '#wcdp-deposits-disabled-notice' ).toggle( off );
        $( '.wcdp-tab-content' ).not( '#second_payment' ).each( function () {
            var $tab = $( this );
            $tab.find( 'table.form-table tr' ).not( $masterRow ).toggleClass( 'wcdp-settings-off', off );
            $tab.find( 'h2' ).not( $masterHeading ).toggleClass( 'wcdp-settings-off', off );
        } );
    }
    // Deposit Mode picks ONE of the two mode sections on General Settings; show only that
    // one. The option is still the old 'wc_deposits_checkout_mode_enabled' yes/no, so
    // 'yes' means Checkout Mode.
    function wcdpSyncDepositMode() {
        var $sel = $( '#wc_deposits_checkout_mode_enabled' );
        if ( ! $sel.length ) { return; }
        var isCheckout = 'yes' === $sel.val();
        $( '#wcdp-mode-checkout' ).toggle( isCheckout );
        $( '#wcdp-mode-product' ).toggle( ! isCheckout );
    }
    $( function () {
        wcdpSyncForceMessage();
        wcdpSyncDepositsEnabled();
        wcdpSyncDepositMode();
        $( document ).on( 'change', '#wc_deposits_storewide_deposit_force_deposit', wcdpSyncForceMessage );
        $( document ).on( 'change', '#wc_deposits_site_wide_disable', wcdpSyncDepositsEnabled );
        $( document ).on( 'change', '#wc_deposits_checkout_mode_enabled', wcdpSyncDepositMode );
    } );
} )( jQuery );
JS;
                wp_add_inline_script('wc-deposits-admin-settings', $inline_js);
            }

        }


    }


    public function settings_tabs_array($tabs)
    {

        $tabs['wc-deposits'] = __('Deposits', 'woocommerce-deposits');
        return $tabs;
    }

    /**
     * @brief Write out settings html
     *
     * @param array $settings ...
     * @return void
     */
    public function settings_tabs_wc_deposits()
    {

        $mode_notice = wcdp_checkout_mode() ? '<span style="padding:5px 10px; color:#fff; background-color:rgba(146, 52, 129, 0.8);">' . __('Checkout Mode Enabled', 'woocommerce-deposits') . '</span>' : '';
        // Always rendered so wcdpSyncDepositsEnabled() can show it the moment the switch
        // changes; hidden here rather than omitted when deposits are on. This heading sits
        // above the tab nav, so the badge shows on every tab.
        $deposits_disabled_notice = '<span id="wcdp-deposits-disabled-notice" style="padding:5px 10px; color:#fff; background-color:rgba(255,63,76,0.8);'
            . (get_option('wc_deposits_site_wide_disable') === 'yes' ? '' : ' display:none;')
            . '">' . __('Deposits are disabled', 'woocommerce-deposits') . '</span>';
        ?>

        <h2><?php echo __('Woocommerce Deposits Settings', 'woocommerce-deposits');
            echo '&nbsp;&nbsp;' . $mode_notice . '&nbsp;&nbsp;' . $deposits_disabled_notice ?> </h2>
        <?php $settings_tabs = apply_filters('wc_deposits_settings_tabs', array(
        'wcdp_general' => __('General Settings', 'woocommerce-deposits'),
        // Label only. The 'display_text' KEY is load-bearing: it maps to tab_display_text_output()
        // via method_exists(), to the #display_text container div, and to ?section=display_text
        // in the URL (so it keeps existing bookmarks working). Renaming it would break all three.
        'display_text' => __('Labels & Text', 'woocommerce-deposits'),
        'styling' => __('Styling', 'woocommerce-deposits'),
        'second_payment' => __('Future Payments & Reminders', 'woocommerce-deposits'),
        'gateways' => __('Gateways', 'woocommerce-deposits'),
    )); ?>
        <div class="nav-tab-wrapper wcdp-nav-tab-wrapper">
            <?php
            $count = 0;
            foreach ($settings_tabs as $key => $tab_name) {

                $url = admin_url('admin.php?page=wc-settings&tab=wc-deposits&section=' . $key);


                $count++;
                $active = isset($_GET['section']) ? $key === $_GET['section'] ? true : false : $count === 1;
                ?>
                <a href="<?php echo $url; ?>" class="wcdp nav-tab <?php echo $active ? 'nav-tab-active' : ''; ?>"
                   data-target="<?php echo $key; ?>"><?php echo $tab_name; ?></a>
                <?php
            }
            ?>

        </div>
        <?php
        // echo tabs content
        $count = 0;
        foreach ($settings_tabs as $key => $tab_name) {
            $count++;
            $active = isset($_GET['section']) ? $key === $_GET['section'] ? true : false : $count === 1;
            if (method_exists($this, "tab_{$key}_output")) {
                $this->{"tab_{$key}_output"}($active);

            }
        }
        //allow addons to add their own tab content
        do_action('wc_deposits_after_settings_tabs_content');
    }

    /*** BEGIN TABS CONTENT CALLBACKS **/

    function tab_wcdp_general_output($active)
    {
        $class = $active ? '' : 'hidden';
        ?>
        <div id="wcdp_general" class="wcdp-tab-content <?php echo $class; ?>">

            <?php
            $roles_array = array();
            $user_roles = array_reverse(get_editable_roles());
            foreach ($user_roles as $key => $user_role) {

                $roles_array[$key] = $user_role['name'];
            }
            $manage_plans_link = sprintf(wp_kses(__(' <a  target="_blank" href="%s"> Manage Payment Plans</a>', 'woocommerce-deposits'), array('a' => array('href' => array(), 'target' => array()))), admin_url('/edit-tags.php?taxonomy=wcdp_payment_plan&post_type=product'));

            //payment plans
            $payment_plans = get_terms(array(
                    'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
                    'hide_empty' => false
                )
            );
            $all_plans = array();
            foreach ($payment_plans as $payment_plan) {
                $all_plans[$payment_plan->term_id] = $payment_plan->name;
            }

            //product categories
            $product_categories = get_terms(array(
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false
                )
            );
            $all_categories = array();
            if (!is_wp_error($product_categories)) {
                foreach ($product_categories as $product_category) {
                    $all_categories[$product_category->term_id] = $product_category->name;
                }
            }
            ?>
            <?php $global_settings = array(


                /*
                 * Global settings — the master switch and the mode choice live here, so this
                 * section renders first: everything below it is what the switch grays out,
                 * and which of the two mode sections follows depends on Deposit Mode.
                 */

                'sitewide_title' => array(
                    'name' => __('Global Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => '',
                    'id' => 'wc_deposits_site_wide_title'
                ),
                'deposits_disable' => array(
                    'name' => __('Enable/Disable Deposits', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' => true,
                    'desc' => __('Disabling turns off all deposit functionality on the front end with one click, including Checkout Mode.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_site_wide_disable',
                    'options' => array(
                        'no' => __('Enable Deposits', 'woocommerce-deposits'),
                        'yes' => __('Disable Deposits', 'woocommerce-deposits'),
                    ),
                    'default' => 'no'
                ),
                'deposit_mode' => array(
                    'name' => __('Deposit Mode', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' => true,
                    'options' => array(
                        // Values are unchanged from the old "Enable checkout mode" select
                        // ('no'/'yes'), so wcdp_checkout_mode() and every stored setting keep
                        // working — only the label and the wording changed.
                        'no'  => __('Products Mode', 'woocommerce-deposits'),
                        'yes' => __('Checkout Mode', 'woocommerce-deposits'),
                    ),
                    'desc' => __('Products Mode: each product carries its own deposit, chosen on the product page. Checkout Mode: one deposit calculated from the cart total, chosen at checkout. The settings below change to match.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_enabled',
                    'default' => 'no'
                ),
                'deposits_stock' => array(
                    'name' => __('Reduce Product Inventory On', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose when to reduce stocks.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_reduce_stock',
                    'options' => array(
                        'deposit' => __('Deposit Payment', 'woocommerce-deposits'),
                        'full' => __('Full Payment', 'woocommerce-deposits')
                    ),
                    'default' => 'deposit'
                ),
                'partially_paid_orders_editable' => array(
                    'name' => __('Make partially paid orders editable', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check to make orders editable while in "partially paid" status', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_partially_paid_orders_editable',
                    'default' => 'yes',
                ),

                'order_list_table_show_has_deposit' => array(
                    'name' => __('Show "has deposit" column in admin order list table', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check to show a column in admin order list indicating if order has deposit', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_order_list_table_show_has_deposit',
                ),

                'disable_deposit_for_user_roles' => array(
                    'name' => __('Disable deposit for selected user roles', 'woocommerce-deposits'),
                    'type' => 'multiselect',
                    'desc_tip' =>true,
                    'class' => 'chosen_select',
                    'options' => $roles_array,
                    'desc' => __('Disable deposit for selected user roles', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_disable_deposit_for_user_roles',
                ),

                'restrict_deposits_for_logged_in_users_only' => array(
                    'name' => __('Restrict deposits for logged-in users only', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to disable all deposit functionality for guests', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_restrict_deposits_for_logged_in_users_only',
                ),
                'sitewide_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_site_wide_end'
                ),
            );

            /*
             * Products Mode. Shown only when Deposit Mode = Products Mode;
             * wcdpSyncDepositMode() toggles #wcdp-mode-product against #wcdp-mode-checkout.
             */
            $product_deposit_settings = array(
                'deposit_storewide_values' => array(

                    'name' => __('Product Deposit Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => '',
                    'id' => 'wc_deposits_deposit_storewide_values'
                ),

                'enable_storewide_deposit' => array(
                    'name' => __('Enable deposit for all products', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'options' => array(
                        'no' => __('No', 'woocommerce-deposits'),
                        'yes' => __('Yes', 'woocommerce-deposits'),
                    ),
                    'desc' => __('Enable to turn on deposits for all products', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_storewide_deposit_enabled',
                    'default' => 'no'
                ),
                'storewide_deposit_force_deposit' => array(
                    'name' => __('Force deposit for all products', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'options' => array(
                        'no' => __('No', 'woocommerce-deposits'),
                        'yes' => __('Yes', 'woocommerce-deposits'),
                    ), 'desc' => __('If you enable this, the customer will not be allowed to make a full payment.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_storewide_deposit_force_deposit',
                    'default' => 'no'

                ),
                'storewide_deposit_amount_type' => array(
                    'name' => __('Default Deposit Type', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose amount type', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_storewide_deposit_amount_type',
                    'options' => array(
                        'fixed' => __('Fixed Amount', 'woocommerce-deposits'),
                        'percent' => __('Percentage', 'woocommerce-deposits'),
                    ),
                    'default' => 'percent'
                ),
                'storewide_deposit_amount' => array(
                    'name' => __('Default Deposit Amount', 'woocommerce-deposits'),
                    'type' => 'number',
                    'desc_tip' =>true,
                    'desc' => __('Amount of deposit.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_storewide_deposit_amount',
                    'default' => '50',
                    'custom_attributes' => array(
                        'min' => '0.0',
                        'step' => '0.01'
                    )
                ),
                'storewide_deposit_payment_plans' => array(
                    'name' => __('Default Payment plan(s)', 'woocommerce-deposits'),
                    'type' => 'multiselect',
                    'desc_tip' =>true,
                    'class' => 'chosen_select',
                    'options' => $all_plans,
                    'desc' => __('Selected payment plan(s) will be available for customers to choose from.  ', 'woocommerce-deposits') . $manage_plans_link,
                    'id' => 'wc_deposits_storewide_deposit_payment_plans',
                ),
                'storewide_deposit_excluded_categories' => array(
                    'name' => __('Excluded product categories', 'woocommerce-deposits'),
                    'type' => 'multiselect',
                    'desc_tip' => true,
                    'class' => 'chosen_select',
                    'options' => $all_categories,
                    'desc' => __('Products in these categories will not show the deposit option. Child categories are excluded along with their parent. Products with deposits explicitly enabled in their own settings are unaffected.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_storewide_deposit_excluded_categories',
                ),
                'deposits_default' => array(
                    'name' => __('Default Selection', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose option selected by default when product first loads', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_default_option',
                    'options' => array(
                        'deposit' => __('Pay Deposit', 'woocommerce-deposits'),
                        'full' => __('Full Amount', 'woocommerce-deposits')
                    ),
                    'default' => 'deposit'
                ),
                'deposit_storewide_values_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_deposit_storewide_values_end'
                ),
            );

            /*
             * Checkout Mode. Moved here from its own tab in 5.1.0: it was never a separate
             * area of configuration, it is the OTHER half of the same choice, and a tab
             * whose settings only apply when a select inside it says "yes" hid that.
             * Shown only when Deposit Mode = Checkout Mode.
             */
            $checkout_mode_settings = array(
                'checkout_mode_title' => array(
                    'name' => __('Checkout Mode Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('One deposit is calculated from the cart total and chosen at checkout, instead of per product.', 'woocommerce-deposits'),
                    // Was wc_deposits_messages_title — a copy-paste of the Messages section's
                    // id on the Labels & Text tab, i.e. a duplicate id in the page.
                    'id' => 'wc_deposits_checkout_mode_title'
                ),
                'checkout_mode_force_deposit' => array(
                    'name' => __('Force deposit', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' => true,
                    'options' => array(
                        'no' => __('No', 'woocommerce-deposits'),
                        'yes' => __('Yes', 'woocommerce-deposits'),
                    ),
                    'desc' => __('If you enable this, the customer will not be allowed to make a full payment at checkout.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_force_deposit',
                    'default' => 'no'
                ),
                'checkout_mode_amount_type' => array(
                    'name' => __('Deposit Type', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' => true,
                    'desc' => __('Choose amount type', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_deposit_amount_type',
                    'options' => array(
                        'fixed' => __('Fixed Amount', 'woocommerce-deposits'),
                        'percentage' => __('Percentage', 'woocommerce-deposits'),
                    ),
                    'default' => 'percentage'
                ),
                'checkout_mode_amount_deposit_amount' => array(
                    'name' => __('Deposit Amount', 'woocommerce-deposits'),
                    'type' => 'number',
                    'desc_tip' => true,
                    'desc' => __('Amount of deposit. Should not be more than 99 for a percentage, or more than the order total for a fixed amount.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_deposit_amount',
                    'default' => '50',
                    'custom_attributes' => array(
                        'min' => '0.0',
                        'step' => '0.01'
                    )
                ),
                'checkout_mode_payment_plans' => array(
                    'name' => __('Payment plan(s)', 'woocommerce-deposits'),
                    'type' => 'multiselect',
                    'desc_tip' => true,
                    'class' => 'chosen_select',
                    'options' => $all_plans,
                    'desc' => __('Selected payment plan(s) will be available for customers to choose from.  ', 'woocommerce-deposits') . $manage_plans_link,
                    'id' => 'wc_deposits_checkout_mode_payment_plans'
                ),
                'checkout_mode_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_checkout_mode_end'
                ),
            );

            /*
             * Applies to both modes.
             */
            $calculation_settings = array(
               'calculation_and_structure' => array(

                    'name' => __('Calculation & Structure', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => '',
                    'id' => 'wc_deposits_calculation_and_structure'
                ),
                'partial_payments_structure' => array(
                    'name' => __('Partial Payments Structure', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose how partial payments are created. If single is checked, partial payment will consist of a single fee. 
                                               If "Copy main order items" is selected, items of main order will be created in partial payment.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_partial_payments_structure',
                    'default' => 'single',
                    'options' => array(
                        'single' => __('Single fee item', 'woocommerce-deposits'),
                        'full' => __('Copy main order items', 'woocommerce-deposits')
                    )
                ),
                'taxes_handling' => array(
                    'name' => __('Taxes Collection Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose how to handle taxes.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_taxes_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ),
                'fees_handling' => array(
                    'name' => __('Fees Collection Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose how to handle fees.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_fees_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ),
                'shipping_handling' => array(
                    'name' => __('Shipping Handling Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose how to handle shipping.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_shipping_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ),
                'coupons_handling' => array(
                    'name' => __('Discount Coupons Handling', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc_tip' =>true,
                    'desc' => __('Choose how to handle coupon discounts', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_coupons_handling',
                    'options' => array(
                        'deposit' => __('Deduct from deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'second_payment' => __('Deduct from future payment(s)', 'woocommerce-deposits')
                    ),
                    'default' => 'second_payment'
                ),
                'calculation_and_structure_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_calculation_and_structure_end'
                ),

            );
            ?>
            <?php
            // Rendered as separate groups so the two mode sections can be shown and hidden
            // as units. woocommerce_admin_fields() emits a section as an <h2> followed by a
            // <table>, which cannot be toggled together without a wrapper.
            ?>
            <div id="wcdp-general-global">
                <?php woocommerce_admin_fields($global_settings); ?>
            </div>
            <div id="wcdp-mode-product" class="wcdp-mode-group">
                <?php woocommerce_admin_fields($product_deposit_settings); ?>
            </div>
            <div id="wcdp-mode-checkout" class="wcdp-mode-group">
                <?php woocommerce_admin_fields($checkout_mode_settings); ?>
            </div>
            <div id="wcdp-general-calculation">
                <?php woocommerce_admin_fields($calculation_settings); ?>
            </div>
            <?php do_action('wc_deposits_settings_tabs_general_tab'); ?>

        </div>
        <?php
    }

    function tab_display_text_output($active)
    {

        $class = $active ? '' : 'hidden';
        ?>
        <div id="display_text" class="wcdp-tab-content wrap <?php echo $class; ?>">
            <?php
            $text_to_replace = __('Text to replace ', 'woocommerce-deposits');

            $strings_settings = array(

                /*
                 * Section for buttons — the presentation toggle and color controls now
                 * live in the dedicated Styling tab (tab_styling_output). Only the button
                 * TEXT strings remain here.
                 */

                'deposit_choice_strings_title' => array(
                    'name' => __('Deposit choice text', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('No HTML allowed. Text will be translated to the user if a translation is available.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_deposit_choice_strings_title'
                )
            ,
                'deposits_deposit_option_text' => array(
                    'name' => __('Card Heading', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => __('Heading shown at the top of the deposit card on the product page, above the payment choices — e.g. "Reserve this puppy". Style it on the Styling tab under Card Style.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_deposit_option_text',
                    'default' => 'Deposit Option',
                    'css' => 'width:400px;'
                ),
                'deposits_button_deposit' => array(
                    'name' => __('Deposit Button Text', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => __('Text displayed in the \'Pay Deposit\' button.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_button_deposit',
                    'default' => 'Pay Deposit'
                ),
                'deposits_button_full' => array(
                    'name' => __('Full Amount Button Text', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => __('Text displayed in the \'Full Amount\' button.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_button_full_amount',
                    'default' => 'Full Amount'
                ),
                'deposits_balance_row_text' => array(
                    'name' => __('Deposit Option Balance Text', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => __('Second line shown under the deposit option, and under each deposit item in the cart. Use <code>{balance}</code> where the remaining balance amount should appear. Leave blank to hide the line. Note: on the product page this line only appears when Deposit Option Style is set to Radio Buttons (see the Styling tab); the cart line shows it either way.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_deposit_balance_row_text',
                    'default' => 'balance of {balance} due at delivery or pickup',
                    'css' => 'width:400px;'
                ),
                'full_unavailable_text' => array(
                    'name' => __('Deposit Disabled Message', 'woocommerce-deposits'),
                    'type' => 'text',
                    'id' => 'wc_deposits_full_unavailable_text',
                    'css' => 'width:100%;max-width:520px;',
                    'default' => __("Full payment isn't available for this item — a deposit is required to reserve it.", 'woocommerce-deposits'),
                    'desc' => __('Shown when a forced deposit disables the "Pay in Full" option: as a subline under that option in Radio Buttons style, or as a hover/tap tooltip in Toggle Switch style. Leave blank to show nothing. Only visible here when Force deposit for all products is set to Yes (General Settings).', 'woocommerce-deposits'),
                ),
                'deposit_choice_strings_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_deposit_choice_strings_end'
                ),
                'checkout_and_order_strings' => array(
                    'name' => __('Checkout & Order text', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('No HTML allowed. Text will be translated to the user if a translation is available.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_strings_title'
                ),

                'deposits_to_pay_text' => array(
                    'name' => __('To Pay', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('To Pay', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_to_pay_text',
                    'default' => 'To Pay'
                ),
                'deposits_deposit_amount_text' => array(
                    'name' => __('Deposit Amount', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Deposit Amount', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_deposit_amount_text',
                    'default' => 'Deposit Amount'
                ),
                'deposits_second_payment_text' => array(
                    'name' => __('Future Payments', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Future Payments', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_second_payment_text',
                    'default' => 'Future Payments'
                ),
                'deposits_payment_link_text' => array(
                    'name' => __('Payment Link', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Payment Link', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_payment_link_text',
                    'default' => 'Payment Link'
                ),

                'strings_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_strings_end'
                ),
                /*
                 * Section for messages
                 */

                'messages_title' => array(
                    'name' => __('Messages', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('Please check the documentation for allowed HTML tags.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_messages_title'
                ),
                'deposits_message_deposit' => array(
                    'name' => __('Deposit Message', 'woocommerce-deposits'),
                    'type' => 'textarea',
                    'desc' => __('Message to show when \'Pay Deposit\' is selected on the product\'s page.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_message_deposit',
                ),
                'deposits_message_full' => array(
                    'name' => __('Full Amount Message', 'woocommerce-deposits'),
                    'type' => 'textarea',
                    'desc' => __('Message to show when \'Full Amount\' is selected on the product\'s page.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_message_full_amount',
                ),
                'messages_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_messages_end'
                ),

                /*
                 * The only non-text section on this tab: three switches for how much detail
                 * to surface, rather than what wording to use. Sits last because the tab is
                 * primarily about wording, and these are set once and rarely revisited.
                 */
                'display_title' => array(
                    'name' => __('Additional Details', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('Control how much extra detail is shown alongside deposits.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_display_text_title'
                ),
                'override_payment_form' => array(
                    'name' => __('Show order details on balance payments', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('When a customer pays a remaining balance from a payment link, show the original order\'s products instead of WooCommerce\'s bare "pay for order" page. Leave off if your theme or a checkout plugin heavily customises the payment page.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_override_payment_form',
                    'default' => 'no',
                ),
                'deposits_tax' => array(
                    'name' => __('Show tax in product-page deposit amount', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Include tax in the deposit and balance figures shown on the product page. Changes the displayed amounts only — not what the customer is actually charged.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_tax_display',
                ),
                'deposits_breakdown_cart_tooltip' => array(
                    'name' => __('Display Deposit-breakdown Tooltip in cart', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check to display tooltip in cart totals detailing deposit breakdown', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_breakdown_cart_tooltip',
                ),
                'display_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_display_text_end'
                ),

            );
            woocommerce_admin_fields($strings_settings);
            ?>
            <?php do_action('wc_deposits_settings_tabs_display_text_tab'); ?>
        </div>
        <?php
    }

    /**
     * @brief Styling tab — full visual control of the product-page deposit selector.
     *
     * A presentation dropdown (Radio Buttons / Toggle Switch) drives which of the two
     * mode-specific groups is shown; the conditional show/hide is handled by the inline
     * JS added in enqueue_settings_script(). Color fields are plain text inputs (class
     * wcdp-style-color) so they can hold a hex value, a CSS variable (var(--token)), or an
     * rgb()/hsl() value; a blank color means "inherit the theme". Values are validated by
     * wcdp_sanitize_style_color() on save.
     */
    function tab_styling_output($active)
    {
        $class = $active ? '' : 'hidden';

        // Section 1 — Card style (the reserve card + heading; applies to both modes).
        $card_settings = array(
            'style_title' => array(
                'name' => __('Card Styles', 'woocommerce-deposits'),
                'type' => 'title',
                'desc' => __('The look of the deposit selector card and its heading on product pages. Leave any field blank to use the theme / the default.', 'woocommerce-deposits'),
                'id' => 'wc_deposits_style_title',
            ),
            'card_bg' => array(
                'name' => __('Card Background Color', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #711919 or var(--action)',
                'id' => 'wc_deposits_style_card_bg',
                'desc' => __('Background fill of the whole deposit card. Blank uses a faint tint of whatever is behind it. Accepts a hex, rgb()/rgba(), or a theme var().', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'card_border_color' => array(
                'name' => __('Card Border Color', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #711919 or var(--action)',
                'id' => 'wc_deposits_style_card_border_color',
                'desc' => __('Color of the card\'s outer border. Blank uses a faint gray.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'card_border_width' => array(
                'name' => __('Card Border Width', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_card_border_width',
                'placeholder' => 'e.g. 1, 2px, var(--…)',
                'desc' => __('Thickness of the card\'s outer border. A bare number is treated as pixels; or give a unit like 2px, or a var(). Blank uses 1px.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'card_radius' => array(
                'name' => __('Card Corner Radius', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_card_radius',
                'placeholder' => 'e.g. 8, 8px, var(--radius)',
                'desc' => __('Corner rounding of the card (and the top corners of the heading band). A bare number is pixels; or a unit (8px), % or a var(). Blank uses 8px.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'card_padding' => array(
                'name' => __('Card Padding', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_card_padding',
                'placeholder' => 'e.g. 16px, 16px 20px, var(--…)',
                'desc' => __('Inner padding of the card body in the Toggle Switch style (the radio rows carry their own spacing). One value = all sides; two values = top/bottom then left/right. Blank uses 1em 1.25em.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'heading_bg' => array(
                'name' => __('Heading Background', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #711919, rgba(0,0,0,.05) or var(--token)',
                'id' => 'wc_deposits_style_heading_bg',
                'desc' => __('Fill behind the Card Heading, which renders as a band across the top of the card. Blank uses a faint tint of whatever is behind it. For a strong band, set this to a solid color and set Heading Color to a light one.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'heading_color' => array(
                'name' => __('Heading Color', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #711919 or var(--action)',
                'id' => 'wc_deposits_style_heading_color',
                'desc' => __('Text color of the Card Heading. Blank inherits the surrounding text color.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'heading_size' => array(
                'name' => __('Heading Font Size', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_heading_size',
                'placeholder' => 'e.g. 17, 1.05em, var(--…)',
                'desc' => __('Font size of the Card Heading. A bare number is pixels; or a unit (1.05em), or a var(). Blank uses 1.05em.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'heading_weight' => array(
                'name' => __('Heading Font Weight', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_heading_weight',                'placeholder' => 'e.g. 700, bold, var(--…)',
                'desc' => __('Injected as a CSS rule rather than an inline style, so it survives themes and page builders that strip inline font-weight from paragraphs. Default 700 (bold).', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            // Option typography — applies to BOTH presentation styles (the toggle's two
            // buttons and the radio rows are the same thing wearing different clothes),
            // with the same defaults, so the two read alike out of the box.
            // Option ids keep their wc_deposits_style_toggle_* names: these started in the
            // Toggle Switch Style section, and renaming ids would orphan stored values.
            'toggle_label_size' => array(
                'name' => __('Option Label Text Size', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_toggle_label_size',
                'placeholder' => 'e.g. 16px, 1rem, var(--…)',
                'desc' => __('Size of the "Pay Deposit" / "Pay in Full" label text, in both styles. Blank inherits the surrounding text size.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'toggle_price_size' => array(
                'name' => __('Price Text Size', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_toggle_price_size',
                'placeholder' => 'e.g. .85em, 1.5rem, 14px, var(--…)',
                'desc' => __('Size of the amount, e.g. ($200.00), shown in each option. Accepts em, rem, px, % or a var(). Relative units scale with the option label, so <code>.85em</code> keeps the price proportional if the label size changes. Blank keeps each style\'s default.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'toggle_weight' => array(
                'name' => __('Option Font Weight', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_toggle_weight',
                'placeholder' => 'e.g. 600, bold, var(--…)',
                'desc' => __('Weight of the option label text, in both styles. Blank uses bold (700).', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            // Belongs to the card, not to either mode: --wcdp-option-divider draws the
            // heading band's bottom border (rendered in BOTH modes) as well as the rule
            // between radio option rows.
            'option_divider' => array(
                'name' => __('Divider Color', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. rgba(0,0,0,.1), #ddd or var(--token)',
                'id' => 'wc_deposits_style_option_divider',
                'desc' => __('The rule under the heading band, in both styles, and between the payment options in the Radio Buttons style.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            // Option id kept as wc_deposits_style_toggle_align for back-compat: this setting
            // started life in the Toggle Switch Style section, and renaming the id would
            // orphan every stored value.
            'toggle_align' => array(
                'name' => __('Header/Message Text Alignment', 'woocommerce-deposits'),
                'type' => 'select',
                'id' => 'wc_deposits_style_toggle_align',
                'default' => 'left',
                'options' => array(
                    'left' => __('Left', 'woocommerce-deposits'),
                    'center' => __('Center', 'woocommerce-deposits'),
                    'right' => __('Right', 'woocommerce-deposits'),
                ),
                'desc' => __('Alignment of the card heading, and of the message shown under the toggle. Option rows keep their own alignment.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'style_end' => array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_style_end',
            ),
        );

        // Section 2 — Options Style. ONE section for both presentations: the dropdown picks
        // Radio Buttons or Toggle Switch, and every setting below applies to whichever is
        // chosen. The old separate "Radio Button Style" and "Toggle Switch Style" sections
        // (and the two-field Selected Button color composite) were merged here.
        $options_settings = array(
            'style_options_title' => array(
                'name' => __('Options Styles', 'woocommerce-deposits'),
                'type' => 'title',
                'desc' => __('How the Pay Deposit / Pay in Full options look. These apply to whichever presentation you pick. Leave any field blank to use the default.', 'woocommerce-deposits'),
                'id' => 'wc_deposits_style_options_title',
            ),
            'presentation' => array(
                'name' => __('Deposit Option Style', 'woocommerce-deposits'),
                'type' => 'select',
                'id' => 'wc_deposits_use_basic_radio_buttons',
                'default' => 'no',
                'options' => array(
                    'yes' => __('Radio Buttons', 'woocommerce-deposits'),
                    'no' => __('Toggle Switch', 'woocommerce-deposits'),
                ),
                'desc' => __('How the choice is presented: a two-option Toggle Switch, or a stacked list of Radio Buttons. Every setting below applies to both.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'selected_bg' => array(
                'name' => __('Selected Option Background', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #711919, rgba(0,0,0,.05) or var(--token)',
                'id' => 'wc_deposits_style_option_selected_bg',
                'desc' => __('Fill behind the SELECTED option (the toggle button, or the radio row). If you set a dark color, set a light Selected Option Text Color too. Blank uses a faint tint. In toggle mode the unselected button is drawn automatically as an 18% tint of this.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'selected_text' => array(
                'name' => __('Selected Option Text Color', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #ffffff or var(--token)',
                'id' => 'wc_deposits_style_selected_text',
                'desc' => __('Text and checkmark color on the SELECTED option, which has the fill above to contrast against. Blank picks black or white automatically for contrast — including when the fill is a theme color token. Unselected options always auto-contrast against their lighter tint.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'option_hover_bg' => array(
                'name' => __('Option Hover Background', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. rgba(0,0,0,.03)',
                'id' => 'wc_deposits_style_option_hover_bg',
                'desc' => __('Fill shown while hovering an unselected option. Keep it lighter than the selected background. Blank uses each style\'s default (the toggle derives a 22% tint of the selected color).', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'option_radius' => array(
                'name' => __('Option Corner Radius', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_toggle_radius',
                'placeholder' => 'e.g. 8px, var(--radius)',
                'desc' => __('Corner rounding of the options — the toggle buttons, and the radio selected-row highlight. A bare number is pixels; or a unit (8px), % or a var(). Blank keeps each default (toggle 3px, radio square).', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'option_padding' => array(
                'name' => __('Option Padding', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_toggle_padding',
                'placeholder' => 'e.g. 12px 24px',
                'desc' => __('Inner padding of each option (toggle button / radio row). One value = all sides; two = top/bottom then left/right. Blank keeps each style\'s default.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'secondary_size' => array(
                'name' => __('Secondary Text Size', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-size',
                'id' => 'wc_deposits_style_toggle_message_size',
                'placeholder' => 'e.g. 14px, var(--…)',
                'desc' => __('Font size of the secondary line — the "balance of $X due…" line under a radio option, and the message under the toggle. Blank uses the default.', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'secondary_color' => array(
                'name' => __('Secondary Text Color', 'woocommerce-deposits'),
                'type' => 'text',
                'class' => 'wcdp-style-color',
                'placeholder' => 'e.g. #711919 or var(--base)',
                'id' => 'wc_deposits_style_subline_color',
                'desc' => __('Color of the secondary line — the balance line under a radio option, and the message under the toggle. Blank inherits (slightly faded).', 'woocommerce-deposits'),
                'desc_tip' => true,
            ),
            'style_options_end' => array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_style_options_end',
            ),
        );
        ?>
        <div id="styling" class="wcdp-tab-content wrap <?php echo $class; ?>">
            <?php // Options Style leads (presentation dropdown + how the options look); Card
                  // Style — the frame around them — comes second. Every setting applies to
                  // both presentations, so there is no per-mode show/hide any more. ?>
            <?php woocommerce_admin_fields($options_settings); ?>
            <?php woocommerce_admin_fields($card_settings); ?>
            <?php do_action('wc_deposits_settings_tabs_styling_tab'); ?>
        </div>
        <?php
    }


    function tab_second_payment_output($active)
    {
        $class = $active ? '' : 'hidden';

        ?>
        <div id="second_payment" class="wcdp-tab-content wrap <?php echo $class; ?>" >


            <?php

            $reminder_settings = array(
                'second_payment_settings' => array(
                    'name' => __('Future Payments Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'id' => 'wc_deposits_second_payment_settings_title'
                ),
                'deposits_payaple' => array(
                    'name' => __('Enable Future Payments', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Uncheck this to prevent the customer from making any payment beyond deposit. (You\'ll have to manually mark the orders as completed)',
                        'woocommerce-deposits'),
                    'id' => 'wc_deposits_remaining_payable',
                    'default' => 'yes',
                ),
            );

            $reminder_settings['second_payment_due_after'] = array(
                'name' => __('Days before Second Payment is due', 'woocommerce-deposits'),
                'type' => 'number',
                'desc' => __('Number of days before second payment is due ( if no payment plan with dates assigned, leave field empty for unlimited days )', 'woocommerce-deposits'),
                'id' => 'wc_deposits_second_payment_due_after',
                'default' => ''
            );
            $statuses = array();
            foreach (wc_get_is_paid_statuses() as $status) {
                $statuses[$status] = wc_get_order_status_name($status);
            }

            $reminder_settings['order_fully_paid_status'] = array(
                'name' => __('Order fully paid status', 'woocommerce-deposits'),
                'type' => 'select',
                'desc' => __('Order status when all partial payments are completed', 'woocommerce-deposits'),
                'id' => 'wc_deposits_order_fully_paid_status',
                'default' => 'fully-paid',
                'options' => $statuses
            );

            $reminder_settings['second_payment_settings_end'] = array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_second_payment_settings_end'
            );

            $reminder_settings['reminder_settings'] = array(
                'name' => __('Reminder Email Settings', 'woocommerce-deposits'),
                'type' => 'title',
                'desc' => __('This section cover automation of reminder emails. ( You can always send a reminder manually from order actions ) ', 'woocommerce-deposits'),
                'id' => 'wc_deposits_reminder_settings_title'
            );

            $reminder_settings['enable_second_payment_reminder'] = array(
                'name' => __('Enable Partial Payment Reminder after "X" Days from deposit', 'woocommerce-deposits'),
                'type' => 'checkbox',
                'desc' => __('Check this to enable sending payment reminder email automatically after X number of days of deposit payment.',
                    'woocommerce-deposits'),
                'id' => 'wc_deposits_enable_second_payment_reminder',
                'default' => 'no',
            );
            $reminder_settings['second_payment_reminder_duration'] = array(
                'name' => __('Partial Payment Reminder after "X" days from deposit', 'woocommerce-deposits'),
                'type' => 'number',
                'desc' => __('Duration between partial payment and payment reminder (in days)', 'woocommerce-deposits'),
                'id' => 'wc_deposits_second_payment_reminder_duration',
                'default' => '14'
            );
            $reminder_settings['enable_partial_payment_reminder'] = array(
                'name' => sprintf(__('Enable %s "X" days before due date', 'woocommerce-deposits'), __('Partial Payment reminder', 'woocommerce-deposits')),
                'type' => 'checkbox',
                'desc' => sprintf(__('Check this to enable %s "X" days before due date', 'woocommerce-deposits'), __('Partial Payment reminder', 'woocommerce-deposits')),
                'id' => 'wc_deposits_enable_partial_payment_reminder',
                'default' => 'yes',
            );
            $reminder_settings['partial_payment_reminder_x_days_before_due_date'] = array(
                'name' => __('Partial Payment Reminder "X" days before due date', 'woocommerce-deposits'),
                'type' => 'number',
                'desc' => __('Send a reminder email x days before partial payment due date', 'woocommerce-deposits'),
                'id' => 'wc_deposits_partial_payment_reminder_x_days_before_due_date',
                'default' => '3'
            );
            $reminder_settings['reminder_settings_end'] = array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_reminder_settings_end'
            );

            $reminder_settings['custom_reminder_datepicker_title'] = array(
                'name' => __('Custom Remainder Email Settings', 'woocommerce-deposits'),
                'type' => 'title',
                'id' => 'wc_deposits_custom_reminder_datepicker_title'
            );

            $reminder_settings['reminder_datepicker'] = array(
                'type' => 'reminder_datepicker',
                'class' => 'reminder_datepicker_html',
            );
            $reminder_settings['custom_reminder_datepicker_end'] = array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_custom_reminder_datepicker_end'
            );

            woocommerce_admin_fields($reminder_settings);

            ?>
            <?php do_action('wc_deposits_settings_tabs_second_payment_tab'); ?>

        </div>

        <?php
    }

    function tab_gateways_output($active)
    {
        $class = $active ? '' : 'hidden';

        ?>
        <div id="gateways" class="wcdp-tab-content wrap <?php echo $class; ?>">

            <?php

            /*
     * Allowed gateways
     */

            $gateways_settings = array();

            $gateways_settings['gateways_title'] = array(
                'name' => __('Disallowed Gateways', 'woocommerce-deposits'),
                'type' => 'title',
                'desc' => __('Disallow the following gateways when there is a deposit in the cart.', 'woocommerce-deposits'),
                'id' => 'wc_deposits_gateways_title'
            );

            $gateways_array = array();
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['wc-booking-gateway'])) unset($gateways['wc-booking-gateway']);// Protect the wc-booking-gateway

            foreach ($gateways as $key => $gateway) {

                $gateways_array[$key] = $gateway->title;
            }


            $gateways_settings['wc_deposits_disallowed_gateways_for_deposit'] = array(
                'name' => __('Disallowed For Deposits', 'woocommerce-deposits'),
                'type' => 'multiselect',
                'class' => 'chosen_select',
                'options' => $gateways_array,
                'desc' => __('Disallowed For Deposits', 'woocommerce-deposits'),
                'id' => 'wc_deposits_disallowed_gateways_for_deposit',
            );

            $gateways_settings['wc_deposits_disallowed_gateways_for_second_payment'] = array(
                'name' => __('Disallowed For Partial Payments', 'woocommerce-deposits'),
                'type' => 'multiselect',
                'class' => 'chosen_select',
                'options' => $gateways_array,
                'desc' => __('Disallowed For Partial Payments', 'woocommerce-deposits'),
                'id' => 'wc_deposits_disallowed_gateways_for_second_payment',
            );


            $gateways_settings['gateways_end'] = array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_gateways_end'
            );


            woocommerce_admin_fields($gateways_settings);

            ?>
            <?php do_action('wc_deposits_settings_tabs_gateways_tab'); ?>

        </div>

        <?php
    }

    /*** END TABS CONTENT CALLBACKS **/

    /*** BEGIN DEPOSIT OPTIONS CUSTOM FIELDS CALLBACKS **/
    function reminder_datepicker()
    {

        $reminder_date = get_option('wc_deposits_reminder_datepicker');
        ob_start();

        ?>
        <script>
            jQuery(function ($) {
                'use strict';

                $("#reminder_datepicker").datepicker({

                    dateFormat: "dd-mm-yy",
                    minDate: new Date()

                }).datepicker("setDate", "<?php echo esc_js($reminder_date); ?>");
            });
        </script>
        <p>
            <b><?php echo __('If you would like to send out all partial payment reminders on a specific date in the future, set a date below.', 'woocommerce-deposits'); ?></b>
        </p>
        <p> <?php echo __('Next Custom Reminder Date :', 'woocommerce-deposits') ?> <input type="text"
                                                                                              name="wc_deposits_reminder_datepicker"
                                                                                              id="reminder_datepicker">
        </p>
        <?php
        echo ob_get_clean();
    }

    public function deposit_buttons_color()
    {

        $colors = get_option('wc_deposits_deposit_buttons_colors', array('primary' => '', 'secondary' => ''));
        $primary_color = isset($colors['primary']) ? $colors['primary'] : '';
        $secondary_color = isset($colors['secondary']) ? $colors['secondary'] : '';

        ?>
        <tr class="">
            <th scope="row"
                class="titledesc"><label for="wc_deposits_deposit_buttons_colors_primary"><?php echo __('Selected Button Background Color', 'woocommerce-deposits'); echo wc_help_tip( __('Background of the selected option in the deposit toggle. The unselected option is drawn automatically as a 20% tint of this, so the two always match — there is nothing separate to set.', 'woocommerce-deposits') ); ?></label></th>
            <td class="forminp forminp-checkbox">
                <fieldset>
                    <input type="text" id="wc_deposits_deposit_buttons_colors_primary" name="wc_deposits_deposit_buttons_colors_primary" class="wcdp-style-color"
                           placeholder="e.g. #711919, rgba(0,0,0,.12) or var(--token)" style="width:400px;"
                           value="<?php echo esc_attr($primary_color); ?>">
                </fieldset>
            </td>
        </tr>
        <tr class="">
            <th scope="row"
                class="titledesc"><label for="wc_deposits_deposit_buttons_colors_secondary"><?php echo __('Selected Button Text Color', 'woocommerce-deposits'); echo wc_help_tip( __('Text color of the selected option, including its amount. Leave blank to pick black or white automatically for contrast against the selected background — this works even when the background is a theme color token. The unselected option always auto-contrasts against its lighter tint.', 'woocommerce-deposits') ); ?></label></th>
            <td class="forminp forminp-checkbox">
                <fieldset>
                    <input type="text" id="wc_deposits_deposit_buttons_colors_secondary" name="wc_deposits_deposit_buttons_colors_secondary" class="wcdp-style-color"
                           placeholder="e.g. #ffffff or var(--token)" style="width:400px;"
                           value="<?php echo esc_attr($secondary_color); ?>">
                </fieldset>
            </td>
        </tr>
        <?php
    }

    /*** END  DEPOSIT OPTIONS CUSTOM FIELDS CALLBACKS **/

    /**
     * @brief Sanitize a Styling-tab color field.
     *
     * Accepts a hex color (#rgb / #rgba / #rrggbb / #rrggbbaa), a CSS custom-property
     * reference (var(--token) or var(--token, fallback)), an rgb()/rgba()/hsl()/hsla()
     * value, or a CSS color keyword (inherit, currentColor, transparent, red…). This
     * lets a site reference its theme's own design tokens so the deposit UI matches any
     * theme without hard-coded colors. Anything that could break out of the inline
     * style attribute or inject CSS (`; { } < > " \` or url()/expression/@import/
     * javascript:) is rejected, returning '' (blank = "inherit theme").
     *
     * @param string $key POST key.
     * @return string
     */
    private function wcdp_sanitize_style_color($key)
    {
        if (!isset($_POST[$key])) {
            return '';
        }
        $val = trim((string) wp_unslash($_POST[$key]));
        if ('' === $val) {
            return '';
        }

        // Reject characters that could terminate the value or open a new declaration/rule.
        if (preg_match('/[<>{}"\\\\;]/', $val)) {
            return '';
        }
        // Reject known dangerous CSS constructs.
        if (preg_match('/expression|javascript:|url\s*\(|@import|<!--/i', $val)) {
            return '';
        }

        // hex
        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $val)) {
            return $val;
        }
        // var(--token) or var(--token, fallback)
        if (preg_match('/^var\(\s*--[a-z0-9_-]+\s*(?:,[a-z0-9\s#%.,()\/-]+)?\)$/i', $val)) {
            return $val;
        }
        // rgb()/rgba()/hsl()/hsla()
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9%.,\/\s]+\)$/i', $val)) {
            return $val;
        }
        // CSS color keyword / named color (letters only): inherit, currentColor, red…
        if (preg_match('/^[a-z]+$/i', $val)) {
            return $val;
        }

        return '';
    }

    /**
     * @brief Sanitize a Styling-tab size field.
     *
     * Accepts a plain number (16 — the template appends px), a CSS length/percentage with
     * a unit (16px, 1.5rem, 50%), a space-separated list of lengths (16px 20px, for
     * padding/radius shorthand), a CSS variable (var(--token) / var(--token, fallback)),
     * or a calc() expression. Anything that could break out of the inline style attribute
     * or inject CSS is rejected, returning '' (blank = "use the default").
     *
     * @param string $key POST key.
     * @return string
     */
    private function wcdp_sanitize_style_size($key)
    {
        if (!isset($_POST[$key])) {
            return '';
        }
        $val = trim((string) wp_unslash($_POST[$key]));
        if ('' === $val) {
            return '';
        }

        // Reject characters that could terminate the value or open a new declaration/rule.
        if (preg_match('/[<>{}"\\\\;]/', $val)) {
            return '';
        }
        // Reject known dangerous CSS constructs.
        if (preg_match('/expression|javascript:|url\s*\(|@import|<!--/i', $val)) {
            return '';
        }

        // var(--token) or var(--token, fallback)
        if (preg_match('/^var\(\s*--[a-z0-9_-]+\s*(?:,[a-z0-9\s#%.,()\/-]+)?\)$/i', $val)) {
            return $val;
        }
        // calc(...) — digits, units, operators, spaces and parentheses only.
        if (preg_match('/^calc\([a-z0-9\s%.+*\/()-]+\)$/i', $val)) {
            return $val;
        }
        // A number or a space-separated list of numbers/lengths (e.g. 16, 8px, 1.5rem,
        // .85em, 16px 20px). The leading dot is optional-but-allowed: CSS accepts `.85em`,
        // and requiring a digit first silently rejected it (saving blank instead), which is
        // a perfectly natural way to type a fractional em.
        if (preg_match('/^\.?[0-9][0-9a-z%.\s]*$/i', $val)) {
            return $val;
        }

        return '';
    }

    /**
     * @brief Sanitize a Styling-tab font-weight field.
     *
     * Accepts a numeric weight (100–900), a CSS keyword (normal, bold, bolder, lighter),
     * or a CSS variable (var(--token) / var(--token, fallback)). Anything else — including
     * CSS-injection attempts — returns '' (blank = "use the default", currently 700).
     *
     * @param string $key POST key.
     * @return string
     */
    private function wcdp_sanitize_style_weight($key)
    {
        if (!isset($_POST[$key])) {
            return '';
        }
        $val = trim((string) wp_unslash($_POST[$key]));
        if ('' === $val) {
            return '';
        }

        if (preg_match('/[<>{}"\\\\;]/', $val)) {
            return '';
        }
        // Numeric weight 100–900 or a CSS keyword.
        if (preg_match('/^(?:[1-9]00|normal|bold|bolder|lighter)$/i', $val)) {
            return $val;
        }
        // var(--token) or var(--token, fallback)
        if (preg_match('/^var\(\s*--[a-z0-9_-]+\s*(?:,[a-z0-9\s#%.,()\/-]+)?\)$/i', $val)) {
            return $val;
        }

        return '';
    }

    /**
     * @brief Save all settings on POST
     *
     * @return void
     */
    public function update_options_wc_deposits()
    {

        
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

        $settings = array();


        // Now a select ('yes' = Disable Deposits, 'no' = Enable Deposits). The stored
        // values are unchanged from the old checkbox, so is_disabled() keeps working.
        $settings ['wc_deposits_site_wide_disable'] = ( isset($_POST['wc_deposits_site_wide_disable']) && $_POST['wc_deposits_site_wide_disable'] === 'yes' ) ? 'yes' : 'no';

        $settings['wc_deposits_default_option'] = isset($_POST['wc_deposits_default_option']) ?
            ($_POST['wc_deposits_default_option'] === 'deposit' ? 'deposit' : 'full') : 'deposit';

        $settings['wc_deposits_reduce_stock'] = isset($_POST['wc_deposits_reduce_stock']) ?
            ($_POST['wc_deposits_reduce_stock'] === 'deposit' ? 'deposit' : 'full') : 'full';
        $settings['wc_deposits_tax_display'] = isset($_POST['wc_deposits_tax_display']) ? 'yes' : 'no';
        $settings['wc_deposits_breakdown_cart_tooltip'] = isset($_POST['wc_deposits_breakdown_cart_tooltip']) ? 'yes' : 'no';
        $settings['wc_deposits_override_payment_form'] = isset($_POST['wc_deposits_override_payment_form']) ? 'yes' : 'no';
        // Presentation is now a select ('yes' = Radio Buttons, 'no' = Toggle Switch).
        $settings['wc_deposits_use_basic_radio_buttons'] = ( isset($_POST['wc_deposits_use_basic_radio_buttons']) && $_POST['wc_deposits_use_basic_radio_buttons'] === 'yes' ) ? 'yes' : 'no';

        // Styling tab — colors (hex or blank) + sizes (px or blank).
        $settings['wc_deposits_style_card_bg']           = $this->wcdp_sanitize_style_color('wc_deposits_style_card_bg');
        $settings['wc_deposits_style_card_border_color'] = $this->wcdp_sanitize_style_color('wc_deposits_style_card_border_color');
        $settings['wc_deposits_style_card_border_width'] = $this->wcdp_sanitize_style_size('wc_deposits_style_card_border_width');
        $settings['wc_deposits_style_card_radius']       = $this->wcdp_sanitize_style_size('wc_deposits_style_card_radius');
        $settings['wc_deposits_style_card_padding']      = $this->wcdp_sanitize_style_size('wc_deposits_style_card_padding');
        $settings['wc_deposits_style_heading_bg']        = $this->wcdp_sanitize_style_color('wc_deposits_style_heading_bg');
        $settings['wc_deposits_style_heading_color']     = $this->wcdp_sanitize_style_color('wc_deposits_style_heading_color');
        $settings['wc_deposits_style_option_divider']    = $this->wcdp_sanitize_style_color('wc_deposits_style_option_divider');
        $settings['wc_deposits_style_option_selected_bg'] = $this->wcdp_sanitize_style_color('wc_deposits_style_option_selected_bg');
        $settings['wc_deposits_style_option_hover_bg']   = $this->wcdp_sanitize_style_color('wc_deposits_style_option_hover_bg');
        $settings['wc_deposits_style_heading_size']      = $this->wcdp_sanitize_style_size('wc_deposits_style_heading_size');
        $settings['wc_deposits_style_heading_weight']    = $this->wcdp_sanitize_style_weight('wc_deposits_style_heading_weight');
        // Toggle-switch button refinements.
        $settings['wc_deposits_style_toggle_label_size'] = $this->wcdp_sanitize_style_size('wc_deposits_style_toggle_label_size');
        $settings['wc_deposits_style_toggle_price_size'] = $this->wcdp_sanitize_style_size('wc_deposits_style_toggle_price_size');
        $settings['wc_deposits_style_toggle_weight']     = $this->wcdp_sanitize_style_weight('wc_deposits_style_toggle_weight');
        $settings['wc_deposits_style_toggle_padding']    = $this->wcdp_sanitize_style_size('wc_deposits_style_toggle_padding');
        $settings['wc_deposits_style_toggle_radius']     = $this->wcdp_sanitize_style_size('wc_deposits_style_toggle_radius');
        $settings['wc_deposits_style_toggle_message_size'] = $this->wcdp_sanitize_style_size('wc_deposits_style_toggle_message_size');
        $align = isset($_POST['wc_deposits_style_toggle_align']) ? $_POST['wc_deposits_style_toggle_align'] : 'left';
        $settings['wc_deposits_style_toggle_align'] = in_array($align, array('left', 'center', 'right'), true) ? $align : 'left';
        $settings['wc_deposits_full_unavailable_text']   = isset($_POST['wc_deposits_full_unavailable_text'])
            ? sanitize_text_field(stripslashes($_POST['wc_deposits_full_unavailable_text']))
            : '';
        // Options Style (unified). selected_text is new; option_selected_bg/hover, subline
        // and option_text are shared. The old wc_deposits_deposit_buttons_colors composite,
        // wc_deposits_style_toggle_message_color and wc_deposits_style_radio_accent are no
        // longer saved (their fields were removed) — left in the DB untouched as emitter
        // fallbacks so a pre-merge store keeps its look until it re-saves with new values.
        $settings['wc_deposits_style_selected_text']     = $this->wcdp_sanitize_style_color('wc_deposits_style_selected_text');
        // wc_deposits_style_option_text_color (Option Text Color) retired when option labels gained auto-contrast; options
        // auto-contrast now, so a single base text color no longer keeps contrast readable.
        $settings['wc_deposits_style_subline_color']     = $this->wcdp_sanitize_style_color('wc_deposits_style_subline_color');

        $settings ['wc_deposits_partially_paid_orders_editable'] = isset($_POST['wc_deposits_partially_paid_orders_editable']) ? 'yes' : 'no';
        $settings ['wc_deposits_order_list_table_show_has_deposit'] = isset($_POST['wc_deposits_order_list_table_show_has_deposit']) ? 'yes' : 'no';
        $settings ['wc_deposits_disable_deposit_for_user_roles'] = isset($_POST['wc_deposits_disable_deposit_for_user_roles']) ? $_POST['wc_deposits_disable_deposit_for_user_roles'] : array();
        $settings ['wc_deposits_restrict_deposits_for_logged_in_users_only'] = isset($_POST['wc_deposits_restrict_deposits_for_logged_in_users_only']) ? 'yes' : 'no';


        //STRINGS
        $settings['wc_deposits_to_pay_text'] = isset($_POST['wc_deposits_to_pay_text']) 
        ? stripslashes($_POST['wc_deposits_to_pay_text']) 
        : 'To Pay';
    
        $settings['wc_deposits_second_payment_text'] = isset($_POST['wc_deposits_second_payment_text']) 
            ? stripslashes($_POST['wc_deposits_second_payment_text']) 
            : 'Future Payments';
        
        $settings['wc_deposits_deposit_amount_text'] = isset($_POST['wc_deposits_deposit_amount_text']) 
            ? stripslashes($_POST['wc_deposits_deposit_amount_text']) 
            : 'Deposit Amount';
        
        $settings['wc_deposits_deposit_option_text'] = isset($_POST['wc_deposits_deposit_option_text']) 
            ? stripslashes($_POST['wc_deposits_deposit_option_text']) 
            : 'Deposit Option';
        
        $settings['wc_deposits_payment_link_text'] = isset($_POST['wc_deposits_payment_link_text']) 
            ? stripslashes($_POST['wc_deposits_payment_link_text']) 
            : 'Payment Link';
    
        // (wc_deposits_deposit_buttons_colors is no longer saved — its two fields were merged
        // into Selected Option Background + Selected Option Text Color. The stored value is
        // left untouched so the emitter can still fall back to it for a pre-merge store.)

        // Deposit Mode. Still the same yes/no option the old "Enable checkout mode" select
        // wrote, so wcdp_checkout_mode() and every stored value carry over untouched.
        $settings['wc_deposits_checkout_mode_enabled'] = ( isset($_POST['wc_deposits_checkout_mode_enabled']) && $_POST['wc_deposits_checkout_mode_enabled'] === 'yes' ) ? 'yes' : 'no';
        $settings['wc_deposits_checkout_mode_force_deposit'] = isset($_POST['wc_deposits_checkout_mode_force_deposit']) ? $_POST['wc_deposits_checkout_mode_force_deposit'] : 'no';
        $settings['wc_deposits_checkout_mode_deposit_amount'] = isset($_POST['wc_deposits_checkout_mode_deposit_amount']) ? $_POST['wc_deposits_checkout_mode_deposit_amount'] : '0';
        $settings['wc_deposits_checkout_mode_deposit_amount_type'] = isset($_POST['wc_deposits_checkout_mode_deposit_amount_type']) ? $_POST['wc_deposits_checkout_mode_deposit_amount_type'] : 'percentage';
        $settings['wc_deposits_checkout_mode_payment_plans'] = isset($_POST['wc_deposits_checkout_mode_payment_plans']) ? $_POST['wc_deposits_checkout_mode_payment_plans'] : array();

        $settings['wc_deposits_partial_payments_structure'] = isset($_POST['wc_deposits_partial_payments_structure']) ? $_POST['wc_deposits_partial_payments_structure'] : 'single';
        $settings['wc_deposits_fees_handling'] = isset($_POST['wc_deposits_fees_handling']) ? $_POST['wc_deposits_fees_handling'] : 'split';
        $settings['wc_deposits_taxes_handling'] = isset($_POST['wc_deposits_taxes_handling']) ? $_POST['wc_deposits_taxes_handling'] : 'split';
        $settings['wc_deposits_shipping_handling'] = isset($_POST['wc_deposits_shipping_handling']) ? $_POST['wc_deposits_shipping_handling'] : 'split';
        $settings['wc_deposits_coupons_handling'] = isset($_POST['wc_deposits_coupons_handling']) ? $_POST['wc_deposits_coupons_handling'] : 'full';



        $settings['wc_deposits_remaining_payable'] = isset($_POST['wc_deposits_remaining_payable']) ? 'yes' : 'no';
        $settings['wc_deposits_enable_second_payment_reminder'] = isset($_POST['wc_deposits_enable_second_payment_reminder']) ? 'yes' : 'no';
        $settings['wc_deposits_second_payment_due_after'] = isset($_POST['wc_deposits_second_payment_due_after']) ? $_POST['wc_deposits_second_payment_due_after'] : '';
        $settings['wc_deposits_second_payment_reminder_duration'] = isset($_POST['wc_deposits_second_payment_reminder_duration']) ? $_POST['wc_deposits_second_payment_reminder_duration'] : '0';
        $settings['wc_deposits_button_deposit'] = isset($_POST['wc_deposits_button_deposit']) 
        ? stripslashes($_POST['wc_deposits_button_deposit']) 
        : __('Pay Deposit', 'woocommerce-deposits');
    
    $settings['wc_deposits_button_full_amount'] = isset($_POST['wc_deposits_button_full_amount'])
        ? stripslashes($_POST['wc_deposits_button_full_amount'])
        : __('Full Amount', 'woocommerce-deposits');

    $settings['wc_deposits_deposit_balance_row_text'] = isset($_POST['wc_deposits_deposit_balance_row_text'])
        ? sanitize_text_field(stripslashes($_POST['wc_deposits_deposit_balance_row_text']))
        : '';

    $settings['wc_deposits_message_deposit'] = isset($_POST['wc_deposits_message_deposit']) 
        ? wp_kses(stripslashes($_POST['wc_deposits_message_deposit']), $allowed_html) 
        : '';
    
    $settings['wc_deposits_message_full_amount'] = isset($_POST['wc_deposits_message_full_amount']) 
        ? wp_kses(stripslashes($_POST['wc_deposits_message_full_amount']), $allowed_html) 
        : '';
    
        //partial payment reminder
        $settings['wc_deposits_order_fully_paid_status'] = isset($_POST['wc_deposits_order_fully_paid_status']) ? $_POST['wc_deposits_order_fully_paid_status'] : 'fully-paid';


        $settings['wc_deposits_enable_partial_payment_reminder'] = isset($_POST['wc_deposits_enable_partial_payment_reminder']) ? 'yes' : 'no';
        $settings['wc_deposits_partial_payment_reminder_x_days_before_due_date'] = isset($_POST['wc_deposits_partial_payment_reminder_x_days_before_due_date']) ? $_POST['wc_deposits_partial_payment_reminder_x_days_before_due_date'] : '3';

        //gateway options
        $settings ['wc_deposits_disallowed_gateways_for_deposit'] = isset($_POST['wc_deposits_disallowed_gateways_for_deposit']) ? $_POST['wc_deposits_disallowed_gateways_for_deposit'] : array();
        $settings ['wc_deposits_disallowed_gateways_for_second_payment'] = isset($_POST['wc_deposits_disallowed_gateways_for_second_payment']) ? $_POST['wc_deposits_disallowed_gateways_for_second_payment'] : array();


        //custom reminder date — strict dd-mm-yy(yy) format only; this value is
        //re-emitted inside admin inline JS, so nothing else may be stored.
        $reminder_datepicker = isset($_POST['wc_deposits_reminder_datepicker']) ? sanitize_text_field(wp_unslash($_POST['wc_deposits_reminder_datepicker'])) : '';
        if (!preg_match('/^\d{1,2}-\d{1,2}-\d{2,4}$/', $reminder_datepicker)) {
            $reminder_datepicker = '';
        }
        $settings['wc_deposits_reminder_datepicker'] = $reminder_datepicker;


        //storewide deposit settings

        $settings['wc_deposits_storewide_deposit_enabled'] = $_POST['wc_deposits_storewide_deposit_enabled'] ?? 'no';
        $settings['wc_deposits_storewide_deposit_force_deposit'] = isset($_POST['wc_deposits_storewide_deposit_force_deposit']) ? $_POST['wc_deposits_storewide_deposit_force_deposit'] : 'no';
        $settings['wc_deposits_storewide_deposit_amount'] = $_POST['wc_deposits_storewide_deposit_amount'] ?? '50';
        if(empty($_POST['wc_deposits_storewide_deposit_amount'])) $settings['wc_deposits_storewide_deposit_amount']  = '50';
        $settings['wc_deposits_storewide_deposit_amount_type'] = isset($_POST['wc_deposits_storewide_deposit_amount_type']) ? $_POST['wc_deposits_storewide_deposit_amount_type'] : 'percent';
        $settings['wc_deposits_storewide_deposit_payment_plans'] = isset($_POST['wc_deposits_storewide_deposit_payment_plans']) ? $_POST['wc_deposits_storewide_deposit_payment_plans'] : array();
        // Term IDs are validated with absint() but stored as strings: WC's multiselect
        // renderer marks options selected via in_array((string) $key, $value, true) — a
        // strict compare that integers never match, leaving the field blank after save.
        $excluded_categories = array();
        if (isset($_POST['wc_deposits_storewide_deposit_excluded_categories']) && is_array($_POST['wc_deposits_storewide_deposit_excluded_categories'])) {
            foreach ($_POST['wc_deposits_storewide_deposit_excluded_categories'] as $excluded_category) {
                $excluded_category = absint($excluded_category);
                if ($excluded_category) {
                    $excluded_categories[] = (string) $excluded_category;
                }
            }
        }
        $settings['wc_deposits_storewide_deposit_excluded_categories'] = $excluded_categories;


        foreach ($settings as $key => $setting) {
            update_option($key, $setting);

        }


    }


}