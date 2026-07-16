<?php

/*Copyright: Â© 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
namespace DepositWP;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Deposits_Rest_Api
 *
 */
class WC_Deposits_Rest_Api
{

    /**
     * @var
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        add_filter('rest_api_init', array($this, 'register_api_endpoints'), 10, 2);
    }


    /**
     * Register rest api endpoints
     * @return void
     */
    public function register_api_endpoints()
    {
        //dynamic id
        register_rest_route('wcdp/v1/orders', '/apply/(?P<order_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'apply_deposit_to_order'),
            'permission_callback' => function($data){



                return current_user_can('manage_woocommerce');
            }
        ));

        register_rest_route('wcdp/v1/orders', '/remove/(?P<order_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'remove_deposit_from_order'),
            'permission_callback' => function(){



                return current_user_can('manage_woocommerce');
            }
        ));

    }

    /**
     * Remove deposit from order
     * @param $request
     * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response
     */
    public function remove_deposit_from_order($request)
    {
        $order_id = $request->get_param('order_id');
        $order = wc_get_order($order_id);

        if(!$order){
            return rest_ensure_response(array('success' => false, 'message' => 'Order not found'));
        }

        //first check if order has deposit
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';
        if($order_has_deposit){

            //remove deposit
            WCDP()->admin_order->remove_all_order_deposit_data($order_id);
        } else{
            return rest_ensure_response(array('success' => false, 'message' => 'Order does not have deposit'));
        }


        return rest_ensure_response(array('success' => true));
    }


    /**
     *  Apply deposit to order
     * @param $request
     * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response
     */
    public function apply_deposit_to_order($request)
    {
        $order_id = $request->get_param('order_id');
        $order = wc_get_order($order_id);

        if(!$order){
            return rest_ensure_response(array('success' => false, 'message' => 'Order not found'));
        }

        //first check if order has deposit
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';
        if($order_has_deposit){
            return rest_ensure_response(array('success' => false, 'message' => 'Order already has deposit'));
        }


        $req_data = $request->get_param('data');


        $items = $order->get_items();



        if (empty($items)) {
            return rest_ensure_response(array('success' => false, 'message' => 'Order has no items'));
        }


        if(empty($req_data)){
            return rest_ensure_response(array('success' => false, 'message' => 'No data provided'));
        }


        $data = array();
        $data['fees'] = isset($req_data['fees_handling']) ? $req_data['fees_handling'] : get_option('wc_deposits_fees_handling', 'split');
        $data['taxes'] = isset($req_data['taxes_handling']) ? $req_data['taxes_handling'] : get_option('wc_deposits_taxes_handling', 'split');
        $data['shipping'] = isset($req_data['shipping_handling']) ? $req_data['shipping_handling'] : get_option('wc_deposits_shipping_handling', 'split');
        $data['coupons'] = isset($req_data['coupons_handling']) ? $req_data['coupons_handling'] : get_option('wc_deposits_coupons_handling', 'split');


        if (isset($req_data['checkout_mode']) ) {
            //checkout mode calculation
            $data['checkout_mode'] = $req_data['checkout_mode'];

        } elseif (isset($req_data['items'])) {

            $data['items'] = $req_data['items'];

        }


        WC_Deposits_Admin_Order::recalculate_deposit_for_order($order, $data);

        return rest_ensure_response(array('success' => true));
    }


    /**
     * Get instance of the class
     * @return self
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

}

WC_Deposits_Rest_Api::instance();