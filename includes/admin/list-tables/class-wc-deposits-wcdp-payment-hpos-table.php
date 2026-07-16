<?php



defined( 'ABSPATH' ) || exit;


class Wc_deposits_wcdp_payment_hpos_table {

public function __construct() {
    add_filter( 'manage_woocommerce_page_wc-orders--wcdp_payment_columns', [ $this, 'add_due_date_column' ], 20 );
    add_action( 'manage_woocommerce_page_wc-orders--wcdp_payment_custom_column', [ $this, 'render_due_date_column' ], 10, 2 );
    add_filter( 'manage_woocommerce_page_wc-orders--wcdp_payment_sortable_columns', [ $this, 'make_due_date_sortable' ] );
    add_filter( 'woocommerce_orders_table_query_clauses', [ $this, 'sort_orders_by_due_date' ], 10, 2 );
}

public function add_due_date_column( $columns ) {
    $new_columns = [];
    foreach ( $columns as $column_name => $column_info ) {
        $new_columns[ $column_name ] = $column_info;
        if ( 'order_status' === $column_name ) {
            $new_columns['due_date'] = __( 'Due Date', 'your-textdomain' );
        }
    }
    return $new_columns;
}

public function render_due_date_column( $column, $order_id ) {
    if ( 'due_date' === $column ) {
        $order = wc_get_order( $order_id );
        $due_date_timestamp = $order->get_meta( '_wc_deposits_partial_payment_date' );

        //compare to current date to see if its overdue
        $current_date = strtotime(date('Y-m-d'));
        $due_date = strtotime(date('Y-m-d', intval($due_date_timestamp))); 
        if($current_date > $due_date){
            echo '<span style="color:red;">' . ( $due_date_timestamp ? date_i18n( get_option( 'date_format' ), intval( $due_date_timestamp ) ) : '-' ) . '</span>';
        }else{
            echo $due_date_timestamp ? date_i18n( get_option( 'date_format' ), intval( $due_date_timestamp ) ) : '-';
        }
        
    }
}

public function make_due_date_sortable( $columns ) {
    $columns['due_date'] = 'due_date';
    return $columns;
}

public function sort_orders_by_due_date( $args, $query ) {
    global $wpdb;
    if (
        isset( $_GET['page'] ) && 'wc-orders--wcdp_payment' === $_GET['page'] &&
        isset( $_GET['orderby'] ) && 'due_date' === $_GET['orderby']
    ) {
        $prefix = $wpdb->get_blog_prefix();
        $args['join'] .= " LEFT JOIN {$prefix}wc_orders_meta AS meta ON {$prefix}wc_orders.id = meta.order_id";
        $order = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';
        $args['orderby'] = "CAST(meta.meta_value AS UNSIGNED) $order";
        $args['where'] .= " AND meta.meta_key = '_wc_deposits_partial_payment_date'";
    }
    return $args;
}
}

new Wc_deposits_wcdp_payment_hpos_table();

