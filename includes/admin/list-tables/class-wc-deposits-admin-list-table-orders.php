<?php
/*Copyright: © 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
namespace DepositWP;

use Automattic\WooCommerce\Utilities\OrderUtil;

if( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if(!class_exists('WC_Deposits_Admin_List_Table_Orders')):
/**
 * @brief Adds `Mark partially paid` to orders bulk actions.
 */

class WC_Deposits_Admin_List_Table_Orders {

	/**
	 * Constructor.
	 */
	public function __construct(  ) {
		// The orders list table lives on a different screen under HPOS
		// (woocommerce_page_wc-orders) than on the legacy post table (edit-shop_order),
		// so the bulk-action hook names differ. Registering only the legacy pair
		// silently dropped "Mark partially paid" once HPOS was enabled. Both are
		// registered; the inapplicable pair simply never fires.
		$bulk_screen_ids = array( 'edit-shop_order' );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$bulk_screen_ids[] = function_exists( 'wc_get_page_screen_id' )
				? wc_get_page_screen_id( 'shop-order' )
				: 'woocommerce_page_wc-orders';
		}
		foreach ( array_unique( array_filter( $bulk_screen_ids ) ) as $bulk_screen_id ) {
			add_filter( "bulk_actions-{$bulk_screen_id}", array( $this, 'order_bulk_actions' ), 10, 1 );
			add_filter( "handle_bulk_actions-{$bulk_screen_id}", array( $this, 'handle_bulk_actions' ), 10, 3 );
		}
		add_action( 'admin_notices', array( $this, 'bulk_admin_notices' ) );


		if(get_option('wc_deposits_order_list_table_show_has_deposit','no') === 'yes'){


            // Public API — Automattic\WooCommerce\Internal\* is explicitly not a stable contract.
            $hpos_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
            $order_screen_id = OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

            if($hpos_enabled){
                add_filter("manage_{$order_screen_id}_columns",array($this,'add_has_deposit_column'));
                add_action("manage_{$order_screen_id}_custom_column",array($this,'populate_has_deposit_column'),10,2);

            } else {

                add_filter("manage_edit-{$order_screen_id}_columns", array($this,'add_has_deposit_column'));
                add_action("manage_{$order_screen_id}_posts_custom_column", array($this,'populate_has_deposit_column'), 10, 2);
            }

       }



	}

    /**
     * adds a new column to order editor page
     * @param $columns
     * @return array|mixed
     */
    function add_has_deposit_column($columns){

        $new_columns = array();

        foreach($columns as $key => $column){

            if($key === 'order_total'){
                $new_columns['wc_deposits_has_deposit'] = esc_html__('Has Deposit','woocommerce-deposits');
            }


            $new_columns[$key] = $column;



        }

        return $new_columns;

    }

    /**
     * Populate "has_deposit" column
     * @param $column
     * @return void
     */
    function populate_has_deposit_column($column, $order_id){


        if ( 'wc_deposits_has_deposit' === $column ) {

            $order = wc_get_order($order_id);
            if($order){

                $order_has_deposit = $order->get_meta( '_wc_deposits_order_has_deposit' , true );

                if($order_has_deposit === 'yes'){
                    echo '<span class="button wcdp_has_deposit">&#10004; '.esc_html__('Yes','woocommerce-deposits').'</span>';
                } else {
                    echo '<span class="button wcdp_no_deposit"> &#10006; '.esc_html__('No','woocommerce').'</span>';
                }
            }
        }

    }

	/**
	 *  Define bulk actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function order_bulk_actions( $actions ) {
		$actions['mark_partially_paid'] = esc_html__( 'Mark partially paid', 'woocommerce-deposits' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param  string $redirect_to URL to redirect to.
	 * @param  string $action      Action name.
	 * @param  array  $ids         List of ids.
	 * @return string
	 */
	function handle_bulk_actions( $redirect_to, $action, $ids ) {
 		if( $action == 'mark_partially_paid' ) {
			$changed = 0;

			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order ) { // an unreadable id would fatal on ->update_status()
					continue;
				}
				$order->update_status( 'partially-paid', esc_html__( 'Order status changed by bulk edit:', 'woocommerce-deposits' ) );
				$changed++;
			}

			$args = array(
				'marked_partially_paid' => true,
				'changed'               => $changed,
			);
			// post_type only belongs on the legacy edit.php screen; the HPOS list table
			// is admin.php?page=wc-orders and has no such query var.
			if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$args['post_type'] = 'shop_order';
			}
			$redirect_to = add_query_arg( $args, $redirect_to );
		}

		return $redirect_to;
	}


    /**
     *  Show confirmation message that order status changed for number of orders.
     * @return void
     */
    function bulk_admin_notices() {
		if ( ! isset( $_REQUEST['marked_partially_paid'] ) ) {
			return;
		}

		// Gate on the screen id rather than $pagenow/$post_type: under HPOS the orders
		// list table is admin.php?page=wc-orders, where $pagenow is 'admin.php' and
		// $post_type is unset — so the old check suppressed this notice entirely.
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';

		$valid_screens = array( 'edit-shop_order' );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$valid_screens[] = function_exists( 'wc_get_page_screen_id' )
				? wc_get_page_screen_id( 'shop-order' )
				: 'woocommerce_page_wc-orders';
		}

		if ( ! in_array( $screen_id, $valid_screens, true ) ) {
			return;
		}

		$number  = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;
		$message = sprintf( _n( 'Order status changed.', '%s order statuses changed.', $number, 'woocommerce-deposits' ), number_format_i18n( $number ) );
		echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';
	}

}

endif;