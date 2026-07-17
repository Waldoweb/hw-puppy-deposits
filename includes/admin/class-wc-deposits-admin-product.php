<?php
/*Copyright: © 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace DepositWP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @brief Adds the necessary panel to the product editor in the admin area
 *
 */
class WC_Deposits_Admin_Product
{


    public $wc_deposits;

    /**
     * WC_Deposits_Admin_Product constructor.
     * @param $wc_deposits
     */
    public function __construct(&$wc_deposits)
    {
        $this->wc_deposits = $wc_deposits;
        // Hook the product admin page
        add_action('woocommerce_product_write_panel_tabs', array($this, 'tab_options_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'tab_options'));

        //add metabox to product editor page
        add_action('add_meta_boxes', array($this, 'add_reminder_meta_box'));
        add_action('woocommerce_process_product_meta', array($this, 'process_reminder_datepicker_values'));


        if (!wcdp_checkout_mode()) {
            add_action('woocommerce_process_product_meta', array($this, 'process_product_meta'));
            add_action('woocommerce_product_bulk_edit_end', array($this, 'product_bulk_edit_end'));
            add_action('woocommerce_product_bulk_edit_save', array($this, 'product_bulk_edit_save'));

            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('woocommerce_variation_options_pricing', array($this, 'variation_override'), 10, 3);
            add_action('woocommerce_save_product_variation', array($this, 'save_product_variation'), 10, 2);
        }
    }


    /**
     * enqueue necessary scripts
     */
    public function enqueue_scripts()
    {
        $is_product_editor = false;
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen)
                $is_product_editor = $screen->id === 'product';
        }

        if ($is_product_editor) {
            wp_enqueue_script('wc-deposits-admin-products', WC_DEPOSITS_PLUGIN_URL . '/assets/js/admin/admin-product.js', array('jquery'), WC_DEPOSITS_VERSION, true);

        }

    }


    /**
     * @brief Adds an extra tab to the product editor
     *
     * @return void
     */
    public function tab_options_tab()
    {
        ?>
        <li class="deposits_tab"><a
                href="#deposits_tab_data"><?php echo esc_html__('Deposit', 'woocommerce-deposits'); ?></a>
        </li><?php

    }

    /**
     * @brief Adds tab contents in product editor
     *
     * @return void
     */
    public function tab_options()
    {
        global $post;
        if (is_null($post)) return;
        $product = wc_get_product($post->ID);
        if (!$product) return;
        if (wcdp_checkout_mode()) {
            ?>
            <div id="deposits_tab_data" class="panel woocommerce_options_panel">

                <h3> <?php echo esc_html__('Checkout Mode enabled', 'woocommerce-deposits'); ?></h3>
                <p><?php echo esc_html__('If you would like to collect deposit on product basis , please disable Checkout mode. ', 'woocommerce-deposits') ?>
                    <a
                            href="<?php echo get_admin_url(null, '/admin.php?page=wc-settings&tab=wc-deposits'); ?>"> <?php echo esc_html__('Go to plugin settings', 'woocommerce-deposits') ?> </a>
                </p>
                <?php do_action('wc_deposits_admin_product_editor_tab_checkout_mode', $product); ?>

            </div>
            <?php
            return;
        }
        $inherit = $product->get_meta('_wc_deposits_inherit_storewide_settings', true);
        $inherit = empty($inherit) || $inherit === 'yes';
        ?>
        <div id="deposits_tab_data" class="panel woocommerce_options_panel">

            <div class="options_group">
                <p class="form-field">
                    <?php woocommerce_wp_select(array(
                        'id' => '_wc_deposits_inherit_storewide_settings',
                        'label' => esc_html__('Inherit store-wide Settings', 'woocommerce-deposits'),
                        'options' => array(
                            'yes' => esc_html__('Yes', 'woocommerce-deposits'),
                            'no' => esc_html__('No', 'woocommerce-deposits')

                        ),
                        'description' => esc_html__('Enable this to Inherit store-wide deposit settings ', 'woocommerce-deposits'),
                        'desc_tip' => true));
                    ?>
                </p>
            </div>

            <div class="<?php echo $inherit ? 'hidden' : ''; ?>  wcdp_deposit_values options_group">
                <p class="form-field">
                    <?php woocommerce_wp_select(array(
                        'id' => '_wc_deposits_enable_deposit',
                        'options' => array(
                            'no' => esc_html__('No', 'woocommerce-deposits'),
                            'yes' => esc_html__('Yes', 'woocommerce-deposits')
                        ),
                        'label' => esc_html__('Enable deposit', 'woocommerce-deposits'),
                        'description' => esc_html__('Enable this to require a deposit for this item.', 'woocommerce-deposits'),
                        'desc_tip' => true));
                    ?>
                    <?php woocommerce_wp_select(array(
                        'id' => '_wc_deposits_force_deposit',
                        'options' => array(
                            'no' => esc_html__('No', 'woocommerce-deposits'),
                            'yes' => esc_html__('Yes', 'woocommerce-deposits')
                        ),
                        'label' => esc_html__('Force deposit', 'woocommerce-deposits'),
                        'description' => esc_html__('If you enable this, the customer will not be allowed to make a full payment.', 'woocommerce-deposits'),
                        'desc_tip' => true));
                    ?>
                </p>
                <p class="form-field">

                    <?php woocommerce_wp_select(array(
                        'id' => '_wc_deposits_amount_type',
                        'label' => esc_html__('Deposit type', 'woocommerce-deposits'),
                        'options' => array(
                            'fixed' => esc_html__('Fixed value', 'woocommerce-deposits'),
                            'percent' => esc_html__('Percentage of price', 'woocommerce-deposits'),
                        )
                    ));

                    $display_payment_plan_field = $product->get_meta('_wc_deposits_amount_type') === 'payment_plan' ? '' : 'hidden';
                    $display_amount_field = $display_payment_plan_field === 'hidden' ? '' : 'hidden';

                    ?>
                    <?php woocommerce_wp_text_input(array(
                        'id' => '_wc_deposits_deposit_amount',
                        'label' => esc_html__('Deposit Amount', 'woocommerce-deposits'),
                        'description' => wp_kses(__('This is the minimum deposited amount.<br/>Note: Tax will be added to the deposit amount you specify here.',
                            'woocommerce-deposits'), array('br' => array())),
                        'type' => 'number',
                        'desc_tip' => true,
                        'wrapper_class' => $display_amount_field,
                        'custom_attributes' => array(
                            'min' => '0.0',
                            'step' => '0.01'
                        )
                    ));

                    $payment_plans = get_terms(array(
                            'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
                            'hide_empty' => false
                        )
                    );

                    $all_plans = array();
                    foreach ($payment_plans as $payment_plan) {
                        $all_plans[$payment_plan->term_id] = $payment_plan->name;
                    }

                    woocommerce_wp_select(array(
                        'id' => "_wc_deposits_payment_plans",
                        'name' => "_wc_deposits_payment_plans[]",
                        'label' => esc_html__('Payment plan(s)', 'woocommerce-deposits'),
                        'description' => esc_html__('Selected payment plan(s) will be available for customers to choose from', 'woocommerce-deposits'),
                        'value' => $product->get_meta('_wc_deposits_payment_plans'),
                        'options' => $all_plans,
                        'desc_tip' => true,
                        'style' => 'width:50%;',
                        'class' => 'wc-enhanced-select ',
                        'wrapper_class' => $display_payment_plan_field,
                        'custom_attributes' => array(
                            'multiple' => 'multiple'
                        )

                    )); ?>
                </p>
                <?php if ($product->is_type('booking')  && method_exists($product,'has_persons') && $product->has_persons()) : // check if the product has a 'booking' type, and if so, check if it has persons. ?>
                    <div class="options_group">
                        <p class="form-field">
                            <?php woocommerce_wp_checkbox(array(
                                'id' => '_wc_deposits_enable_per_person',
                                'label' => esc_html__('Multiply by persons', 'woocommerce-deposits'),
                                'description' => esc_html__('Enable this to multiply the deposit by person count. (Only works when Fixed Value is active)',
                                    'woocommerce-deposits'),
                                'desc_tip' => true));
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>


            <?php do_action('wc_deposits_admin_product_editor_tab', $product); ?>
        </div>
        <?php

    }


    /**
     * adds product reminder metabox to order management sidebar
     */
    public function add_reminder_meta_box()
    {

        if (get_option('wc_deposits_remaining_payable', 'yes') === 'no') return;


        global $post;
        if (is_null($post)) return;
        $product = wc_get_product($post->ID);

        if ($product) {
            add_meta_box('wc_deposits_second_payment_reminder',
                esc_html__('Partial Payment Reminder', 'woocommerce-deposits'),
                array($this, 'wc_deposits_second_payment_reminder'),
                'product',
                'side',
                'low');
        }


    }


    /**
     * @brief second payment reminder metabox callback
     */
    function wc_deposits_second_payment_reminder()
    {
        global $post;
        if (is_null($post)) return;
        $product = wc_get_product($post->ID);

        if (!$product) return;

        $reminder_date = $product->get_meta('_wc_deposits_pbr_reminder_date');
        ob_start();
        ?>

        <script>
            jQuery(function ($) {
                'use strict';

                $("#reminder_datepicker").datepicker({
                    dateFormat: "dd-mm-yy",
                })
                <?php
                if(!empty($reminder_date)){
                ?>
                var reminder_date = "<?php echo esc_js($reminder_date); ?>";
                var parts = reminder_date.split("-");


                reminder_date = new Date(parts[2], parts[1] - 1, parts[0]);
                <?php
                echo '  $("#reminder_datepicker").datepicker("setDate",reminder_date);';
                }
                ?>
            });


        </script>
        <p>
            <b><?php echo esc_html__('If you would like to send out second payment reminder emails to all orders containing this product on a specific date in the future, set a date below.', 'woocommerce-deposits'); ?></b>
        </p>
        <p> <?php echo esc_html__('Next Reminder Date :', 'woocommerce-deposits') ?> <input type="text"
                                                                                       name="wc_deposits_reminder_datepicker"
                                                                                       id="reminder_datepicker"></p>
        <?php
        echo ob_get_clean();
    }

    /**
     * Sanitize the product reminder date — strict dd-mm-yy(yy) only. The stored
     * value is re-emitted inside admin inline JS, so nothing else may be saved.
     *
     * @return string Sanitized date, or '' when invalid/absent.
     */
    protected function sanitize_reminder_datepicker()
    {
        $date = isset($_POST['wc_deposits_reminder_datepicker']) ? sanitize_text_field(wp_unslash($_POST['wc_deposits_reminder_datepicker'])) : '';
        return preg_match('/^\d{1,2}-\d{1,2}-\d{2,4}$/', $date) ? $date : '';
    }

    function process_reminder_datepicker_values($post_id)
    {

        $product = wc_get_product($post_id);

        //custom reminder date
        $datepicker_reminder = $this->sanitize_reminder_datepicker();

        if (!empty($datepicker_reminder)) {
            $product->update_meta_data('_wc_deposits_pbr_reminder_date', $datepicker_reminder);
            $product->save();
        } else {
            $product->delete_meta_data('_wc_deposits_pbr_reminder_date');
            $product->save();
        }
    }


    /**
     * @brief Updates the product's metadata
     *
     * @return void
     */
    public function process_product_meta($post_id)
    {

        $product = wc_get_product($post_id);

        $inherit = isset($_POST['_wc_deposits_inherit_storewide_settings']) ? $_POST['_wc_deposits_inherit_storewide_settings'] : 'yes';
        $enable_deposit = isset($_POST['_wc_deposits_enable_deposit']) ? $_POST['_wc_deposits_enable_deposit'] : 'no';
        $force_deposit = isset($_POST['_wc_deposits_force_deposit']) ? $_POST['_wc_deposits_force_deposit'] : 'no';
        $enable_persons = isset($_POST['_wc_deposits_enable_per_person']) ? 'yes' : 'no';
        $amount_type = isset($_POST['_wc_deposits_amount_type']) ? $_POST['_wc_deposits_amount_type'] : 'fixed';
        $amount = isset($_POST['_wc_deposits_deposit_amount']) &&
        is_numeric($_POST['_wc_deposits_deposit_amount']) ? floatval($_POST['_wc_deposits_deposit_amount']) : 0.0;
        $datepicker_reminder = $this->sanitize_reminder_datepicker();
        $payment_plans = isset($_POST['_wc_deposits_payment_plans']) ? $_POST['_wc_deposits_payment_plans'] : array();


        $product->update_meta_data('_wc_deposits_inherit_storewide_settings', $inherit);
        $product->update_meta_data('_wc_deposits_enable_deposit', $enable_deposit);
        $product->update_meta_data('_wc_deposits_force_deposit', $force_deposit);
        $product->update_meta_data('_wc_deposits_amount_type', $amount_type);
        $product->update_meta_data('_wc_deposits_deposit_amount', $amount);
        $product->update_meta_data('_wc_deposits_payment_plans', $payment_plans);


        //product based reminder date
        if (!empty($datepicker_reminder)) {
            $product->update_meta_data('_wc_deposits_pbr_reminder_date', $datepicker_reminder);
        }


        if ($product->is_type('booking')  && method_exists($product,'has_persons') && $product->has_persons()) {
            $product->update_meta_data('_wc_deposits_enable_per_person', $enable_persons);
        }
        $product->save();


    }

    /**
     * @brief Output bulk-editing UI for products
     *
     * @since 1.5
     */
    public function product_bulk_edit_end()
    {
        ?>
        <label>
            <h4><?php echo esc_html__('Deposit Options', 'woocommerce-deposits'); ?></h4>
        </label>
        <label>
            <span class="title"><?php echo esc_html__('Inherit Storewide Settings ?', 'woocommerce-deposits'); ?></span>
            <span class="input-text-wrap">
            <select class="inherit_storewide" name="_inherit_storewide_deposit">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce'),
                'yes' => esc_html__('Yes', 'woocommerce'),
                'no' => esc_html__('No', 'woocommerce')
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($value) . '</option>';
            }
            ?>
          </select>
          </span>
        </label>

        <label>
            <span class="title"><?php echo esc_html__('Enable Deposit ?', 'woocommerce-deposits'); ?></span>
            <span class="input-text-wrap">
            <select class="enable_deposit" name="_enable_deposit">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce'),
                'yes' => esc_html__('Yes', 'woocommerce'),
                'no' => esc_html__('No', 'woocommerce')
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($value) . '</option>';
            }
            ?>
          </select>
        </span>
        </label>

        <label>
            <span class="title"><?php echo esc_html__('Force Deposit?', 'woocommerce-deposits'); ?></span>
            <span class="input-text-wrap">
            <select class="force_deposit" name="_force_deposit">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce-deposits'),
                'yes' => esc_html__('Yes', 'woocommerce-deposits'),
                'no' => esc_html__('No', 'woocommerce-deposits')
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($value) . '</option>';
            }
            ?>
          </select>
        </span>
        </label>

        <label>
            <span class="title"><?php echo esc_html__('Multiply By Persons?', 'woocommerce-deposits'); ?></span>
            <span class="input-text-wrap">
            <select class="deposit_multiply" name="_deposit_multiply">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce-deposits'),
                'yes' => esc_html__('Yes', 'woocommerce-deposits'),
                'no' => esc_html__('No', 'woocommerce-deposits')
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($value) . '</option>';
            }
            ?>
          </select>
        </span>
        </label>

        <label>
            <span class="title"><?php echo esc_html__('Deposit Type?', 'woocommerce-deposits'); ?></span>
            <span class="input-text-wrap">
            <select class="deposit_type" name="_deposit_type">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce-deposits'),
                'fixed' => esc_html__('Fixed', 'woocommerce-deposits'),
                'percent' => esc_html__('Percentage', 'woocommerce-deposits'),
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($value) . '</option>';
            }
            ?>
          </select>
        </span>
        </label>
        <div class="inline-edit-group">
            <label class="alignleft">
                <span class="title"><?php echo esc_html__('Deposit Amount', 'woocommerce-deposits'); ?></span>
                <span class="input-text-wrap">
            <select class="change_deposit_amount change_to" name="change_deposit_amount">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce-deposits'),
                '1' => esc_html__('Change to:', 'woocommerce-deposits'),
                '2' => esc_html__('Increase by (fixed amount or %):', 'woocommerce-deposits'),
                '3' => esc_html__('Decrease by (fixed amount or %):', 'woocommerce-deposits')
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . $value . '</option>';
            }
            ?>
            </select>
          </span>
            </label>
            <label class="change-input">
                <input style="margin-left:5.4em;" type="text" name="_deposit_amount" class="text deposit_amount"
                       placeholder="<?php echo sprintf(esc_html__('Enter Deposit Amount (%s)', 'woocommerce-deposits'), get_woocommerce_currency_symbol()); ?>"
                       value=""/>
            </label>
        </div>


        <div class="inline-edit-group">
            <label class="alignleft">
                <span class="title"><?php echo esc_html__('Payment Plans', 'woocommerce-deposits'); ?></span>
                <span class="input-text-wrap">

        <select class="change_payment_plans change_to" name="change_payment_plans">
            <?php
            $options = array(
                '' => esc_html__('— No Change —', 'woocommerce-deposits'),
                '1' => esc_html__('Change to:', 'woocommerce-deposits'),
            );
            foreach ($options as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . $value . '</option>';
            }
            ?>
        </select>
          </span>
            </label>
            <label class="change-input">
                <span class="input-text-wrap">
            <select multiple="multiple" class="wc-enhanced-select payment_plans" name="_payment_plans[]">
            <?php

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

            foreach ($all_plans as $key => $value) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($value) . '</option>';
            }
            ?>
          </select>
        </span>
        </div>
        <?php
    }

    /**
     * @brief Save bulk-edits to products
     *
     * @since 1.5
     */
    function product_bulk_edit_save($product)
    {
        if (!empty($_REQUEST['_inherit_storewide_deposit'])) {
            $product->update_meta_data('_wc_deposits_inherit_storewide_settings', wc_clean($_REQUEST['_inherit_storewide_deposit']));
        }

        if (!empty($_REQUEST['_enable_deposit'])) {
            $product->update_meta_data('_wc_deposits_enable_deposit', wc_clean($_REQUEST['_enable_deposit']));
        }
        if (!empty($_REQUEST['_force_deposit'])) {
            $product->update_meta_data('_wc_deposits_force_deposit', wc_clean($_REQUEST['_force_deposit']));

        }
        if (!empty($_REQUEST['_deposit_multiply']) && $product->is_type('booking')  && method_exists($product,'has_persons') && $product->has_persons()) {
            $product->update_meta_data('_wc_deposits_enable_per_person', wc_clean($_REQUEST['_deposit_multiply']));

        }
        if (!empty($_REQUEST['_deposit_type'])) {
            $product->update_meta_data('_wc_deposits_amount_type', wc_clean($_REQUEST['_deposit_type']));

        }

        if (!empty($_REQUEST['change_payment_plans'])) {

            if (!empty($_REQUEST['_payment_plans'])) {
                $product->update_meta_data('_wc_deposits_payment_plans', wc_clean($_REQUEST['_payment_plans']));
            }
        }
        if (!empty($_REQUEST['change_deposit_amount'])) {
            $change_deposit_amount = absint($_REQUEST['change_deposit_amount']);
            $deposit_amount = esc_attr(stripslashes($_REQUEST['_deposit_amount']));
            $old_deposit_amount = $product->wc_deposits_deposit_amount;
            switch ($change_deposit_amount) {
                case 1 :
                    $new_deposit_amount = $deposit_amount;
                    break;
                case 2 :
                    if (strstr($deposit_amount, '%')) {
                        $percent = str_replace('%', '', $deposit_amount) / 100;
                        $new_deposit_amount = $old_deposit_amount + ($old_deposit_amount * $percent);
                    } else {
                        $new_deposit_amount = $old_deposit_amount + $deposit_amount;
                    }
                    break;
                case 3 :
                    if (strstr($deposit_amount, '%')) {
                        $percent = str_replace('%', '', $deposit_amount) / 100;
                        $new_deposit_amount = max(0, $old_deposit_amount - ($old_deposit_amount * $percent));
                    } else {
                        $new_deposit_amount = max(0, $old_deposit_amount - $deposit_amount);
                    }
                    break;
                case 4 :
                    if (strstr($deposit_amount, '%')) {
                        $percent = str_replace('%', '', $deposit_amount) / 100;
                        $new_deposit_amount = max(0, $product->regular_price - ($product->regular_price * $percent));
                    } else {
                        $new_deposit_amount = max(0, $product->regular_price - $deposit_amount);
                    }
                    break;

                default :
                    break;
            }

            if (isset($new_deposit_amount) && $new_deposit_amount != $old_deposit_amount) {
                $new_deposit_amount = round($new_deposit_amount, wc_get_price_decimals());
                $product->update_meta_data('_wc_deposits_deposit_amount', $new_deposit_amount);


                $product->wc_deposits_deposit_amount = $new_deposit_amount;
            }
        }


        $product->save();

    }


    function variation_override($loop, $variation_data, $variation)
    {

        $variation_product = wc_get_product($variation->ID);

        $hidden = $variation_product->get_meta('_wc_deposits_override_product_settings', true) === 'yes' ? '' : 'hidden';
        ?>
        <p class="form-field wc_deposits_title  form-row form-row-full">
        <hr/>
        <strong><span> <?php echo esc_html__('Deposit settings', 'woocommerce-deposits'); ?></span></strong>
        </p>
        <?php

        woocommerce_wp_checkbox(array(
            'id' => "_wc_deposits_override_product_settings{$loop}",
            'class' => 'wc_deposits_variation_checkbox wc_deposits_override_product_settings',
            'name' => "_wc_deposits_override_product_settings[{$loop}]",
            'value' => $variation_product->get_meta('_wc_deposits_override_product_settings', true),
            'wrapper_class' => 'form-row form-row-full',
            'custom_attributes' => array(
                'data-loop' => $loop,
            ),
            'style' => 'float:left;',
            'label' => esc_html__('Override product deposits options', 'woocommerce-deposits'),
            'description' => esc_html__('Enable to override deposit settings of parent', 'woocommerce-deposits'),
            'desc_tip' => true));
        ?>


        <?php woocommerce_wp_select(array(
        'id' => "_wc_deposits_enable_deposit{$loop}",
        'name' => "_wc_deposits_enable_deposit[{$loop}]",
        'options' => array(
            'no' => esc_html__('No', 'woocommerce-deposits'),
            'yes' => esc_html__('Yes', 'woocommerce-deposits')
        ),
        'value' => $variation_product->get_meta('_wc_deposits_enable_deposit', true),
        'class' => 'wc_deposits_variation_checkbox',
        'wrapper_class' => "form-row form-row-first  wc_deposits_field{$loop} $hidden ",
        'label' => esc_html__('Enable deposit', 'woocommerce-deposits'),
        'description' => esc_html__('Enable this to require a deposit for this item.', 'woocommerce-deposits'),
        'desc_tip' => true));
        ?>
        <?php woocommerce_wp_select(array(
        'id' => "_wc_deposits_force_deposit{$loop}",
        'name' => "_wc_deposits_force_deposit[{$loop}]",
        'options' => array(
            'no' => esc_html__('No', 'woocommerce-deposits'),
            'yes' => esc_html__('Yes', 'woocommerce-deposits')
        ),
        'value' => $variation_product->get_meta('_wc_deposits_force_deposit', true),
        'class' => 'wc_deposits_variation_checkbox',
        'wrapper_class' => " form-row form-row-last  wc_deposits_field{$loop} $hidden ",
        'label' => esc_html__('Force deposit', 'woocommerce-deposits'),
        'description' => esc_html__('If you enable this, the customer will not be allowed to make a full payment.',
            'woocommerce-deposits'), 'desc_tip' => true));
        ?>

        <?php woocommerce_wp_select(array(
        'id' => "_wc_deposits_amount_type{$loop}",
        'name' => "_wc_deposits_amount_type[{$loop}]",
        'value' => $variation_product->get_meta('_wc_deposits_amount_type', true),
        'class' => 'wc_deposits_varitaion_amount_type',
        'custom_attributes' => array(
            'data-loop' => $loop,
        ),
        'wrapper_class' => "form-row form-row-first wc_deposits_field{$loop} $hidden ",
        'label' => esc_html__('Deposit type', 'woocommerce-deposits'),
        'options' => array(
            'fixed' => esc_html__('Fixed value', 'woocommerce-deposits'),
            'percent' => esc_html__('Percentage of price', 'woocommerce-deposits'),
        )
    ));

        $payment_plans = get_terms(array(
                'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
                'hide_empty' => false
            )
        );

        $all_plans = array();
        foreach ($payment_plans as $payment_plan) {
            $all_plans[$payment_plan->term_id] = $payment_plan->name;
        }

        woocommerce_wp_select(array(
            'id' => "_wc_deposits_payment_plans{$loop}",
            'name' => "_wc_deposits_payment_plans[{$loop}][]",
            'label' => esc_html__('Payment plan(s)', 'woocommerce-deposits'),
            'description' => esc_html__('Selected payment plan(s) will be available for customers to choose from', 'woocommerce-deposits'),
            'value' => $variation_product->get_meta('_wc_deposits_payment_plans'),
            'wrapper_class' => "form-row form-row-last wc_deposits_field{$loop}",
            'class' => "wc-enhanced-select wc_deposits_payment_plans_field",
            'options' => $all_plans,
            'desc_tip' => true,
            'style' => 'width:50%;',
            'custom_attributes' => array(
                'multiple' => 'multiple'
            )

        ));


        woocommerce_wp_text_input(array(
            'id' => "_wc_deposits_deposit_amount{$loop}",
            'name' => "_wc_deposits_deposit_amount[{$loop}]",
            'value' => $variation_product->get_meta('_wc_deposits_deposit_amount', true),

            'wrapper_class' => " form-row form-row-last  wc_deposits_field{$loop} $hidden ",
            'label' => esc_html__('Deposit Amount', 'woocommerce-deposits'),
            'description' => wp_kses(__('This is the minimum deposited amount.<br/>Note: Tax will be added to the deposit amount you specify here.',
                'woocommerce-deposits'), array('br' => array())),
            'type' => 'number',
            'desc_tip' => true,
            'custom_attributes' => array(
                'min' => '0.0',
                'step' => '0.01'
            )
        ));

        do_action('wc_deposits_admin_variation_editor_tab', $loop, $variation_data, $variation);


        ?>
        <p class="form-field wc_deposits_end  form-row form-row-full">
        <hr/>
        </p>

        <?php

    }

    function save_product_variation($variation_id, $i)
    {
        if(did_action('wp_ajax_woocommerce_bulk_edit_variations')) return;

        $variation = wc_get_product($variation_id);
        $override_product_settings = isset($_POST['_wc_deposits_override_product_settings'][$i]) ? 'yes' : 'no';
        $enable_deposit = isset($_POST['_wc_deposits_enable_deposit'][$i]) ? $_POST['_wc_deposits_enable_deposit'][$i] : 'no';
        $force_deposit = isset($_POST['_wc_deposits_force_deposit'][$i]) ? $_POST['_wc_deposits_force_deposit'][$i] : 'no';
        $amount_type = isset($_POST['_wc_deposits_amount_type'][$i]) ? $_POST['_wc_deposits_amount_type'][$i] : 'fixed';

        $amount = isset($_POST['_wc_deposits_deposit_amount'][$i]) &&
        is_numeric($_POST['_wc_deposits_deposit_amount'][$i]) ? floatval($_POST['_wc_deposits_deposit_amount'][$i]) : 0.0;
        $payment_plans = isset($_POST['_wc_deposits_payment_plans'][$i]) ? $_POST['_wc_deposits_payment_plans'][$i] : array();


        $variation->update_meta_data("_wc_deposits_override_product_settings", $override_product_settings);
        $variation->update_meta_data("_wc_deposits_enable_deposit", $enable_deposit);
        $variation->update_meta_data("_wc_deposits_force_deposit", $force_deposit);
        $variation->update_meta_data("_wc_deposits_amount_type", $amount_type);
        $variation->update_meta_data("_wc_deposits_deposit_amount", $amount);
        $variation->update_meta_data('_wc_deposits_payment_plans', $payment_plans);

        $variation->save();


    }

}
