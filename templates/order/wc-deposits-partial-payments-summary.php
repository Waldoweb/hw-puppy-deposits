<?php
/**
 * Order details Summary
 *
 * This template displays a summary of partial payments
 *
 * @package DepositWP\Templates
 * @version 3.2.6
 */


if (!defined('ABSPATH')) {
    exit;
}

if (!$order = wc_get_order($order_id)) {
    return;
}

/*
 * Puppy Deposits: a clear "Deposit & balance" summary computed from the order's
 * own data — the parent order holds the FULL total, each installment is a child
 * order, so amount paid = sum of paid child orders and the balance is the
 * remainder. Inline-styled so no global stylesheet is loaded.
 */
$price_args   = array( 'currency' => $order->get_currency() );
$parent_total = (float) $order->get_total();
$amount_paid       = 0.0;
$balance_remaining = 0.0;
if ( is_array( $schedule ) ) {
    foreach ( $schedule as $__payment ) {
        if ( empty( $__payment['id'] ) ) {
            continue;
        }
        $__po = wc_get_order( $__payment['id'] );
        if ( ! $__po ) {
            continue;
        }
        // Sum paid vs. unpaid installments directly, so a fully-paid order always
        // nets to a $0 balance even if child totals don't reconcile exactly with the
        // parent total (tax/rounding).
        if ( $__po->is_paid() ) {
            $amount_paid += (float) $__po->get_total();
        } else {
            $balance_remaining += (float) $__po->get_total();
        }
    }
}

$summary_rows = array(
    array( esc_html__( 'Order total', 'woocommerce-deposits' ), wc_price( $parent_total, $price_args ) ),
    array( esc_html__( 'Amount paid', 'woocommerce-deposits' ), wc_price( $amount_paid, $price_args ) ),
    array( esc_html__( 'Balance remaining', 'woocommerce-deposits' ), wc_price( $balance_remaining, $price_args ) ),
);
?>

<section class="wc-deposits-balance-summary" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid rgba(0,0,0,0.12);border-radius:8px;background:rgba(0,0,0,0.03);">
    <h2 class="woocommerce-column__title" style="margin:0 0 0.5em;font-size:1.15em;"><?php echo esc_html__( 'Deposit &amp; balance', 'woocommerce-deposits' ); ?></h2>
    <table style="width:100%;border-collapse:collapse;">
        <tbody>
        <?php foreach ( $summary_rows as $__row ) : ?>
            <tr>
                <th scope="row" style="text-align:left;padding:0.35em 0;font-weight:600;"><?php echo esc_html( $__row[0] ); ?></th>
                <td style="text-align:right;padding:0.35em 0;"><?php echo wp_kses_post( $__row[1] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php ?> <h2 class="woocommerce-column__title"> <?php echo esc_html__('Partial payments summary', 'woocommerce-deposits') ?></h2>


<table class="woocommerce-table  woocommerce_deposits_parent_order_summary">

    <thead>
    <tr>

        <th ><?php echo esc_html__('Payment', 'woocommerce-deposits'); ?> </th>
        <th ><?php echo esc_html__('Payment ID', 'woocommerce-deposits'); ?> </th>
        <th><?php echo esc_html__('Status', 'woocommerce-deposits'); ?> </th>
        <th><?php echo esc_html__('Amount', 'woocommerce-deposits'); ?> </th>
        <?php if(is_account_page() && function_exists('WPO_WCPDF')){
            ?><th> </th><?php
        }?>

    </tr>

    </thead>

    <tbody>
    <?php foreach($schedule as $timestamp => $payment){

        $title = '';

        if(isset($payment['title'])){

            $title  = $payment['title'];
        } else {
            if(isset($payment['timestamp'])){
                $timestamp = $payment['timestamp'];
            }

            if (!is_numeric($timestamp)) {
                $title = '-';
            } else {
                $title = date_i18n(wc_date_format(), $timestamp);
            }
        }

        $title = apply_filters('wc_deposits_partial_payment_title',$title,$payment);

        $payment_order = false;
        if(isset($payment['id']) && !empty($payment['id'])) $payment_order = wc_get_order($payment['id']);

        if(!$payment_order) continue;
        $payment_id = $payment_order ? $payment_order->get_order_number(): '-';
        $status = $payment_order ? wc_get_order_status_name($payment_order->get_status()) : '-';
        $amount = $payment_order ? $payment_order->get_total() : $payment['total'];
        $price_args = array('currency' => $payment_order->get_currency());
        $link = '';


        if(is_account_page() && function_exists('WPO_WCPDF')){
            $documents = WPO_WCPDF()->documents->get_documents();
            if ($documents) {
                foreach ($documents as $document) {


                    if ($document->is_enabled() && $document->get_type() === 'partial_payment_invoice') {

                        $invoice = wcpdf_get_document('partial_payment_invoice', $payment_order, false);
                        $button_setting = $invoice->get_setting( 'my_account_buttons', 'available' );
                        switch ( $button_setting ) {
                            case 'available':
                                $invoice_allowed = $invoice->exists();
                                break;
                            case 'always':
                                $invoice_allowed = true;
                                break;
                            case 'never':
                                $invoice_allowed = false;
                                break;
                            case 'custom':
                                $allowed_statuses = $button_setting = $invoice->get_setting( 'my_account_restrict', array() );
                                if ( !empty( $allowed_statuses ) && in_array( $payment_order->get_status(), array_keys( $allowed_statuses ) ) ) {
                                    $invoice_allowed = true;
                                } else {
                                    $invoice_allowed = false;
                                }
                                break;
                        }
                        $classes = $invoice && $invoice->exists() ? 'wcdp_invoice_exists' : '';

                        if ( $invoice_allowed ) {
                            $link .= '<a class="button btn ' . $classes . '" href="';
                            $link .= wp_nonce_url(admin_url("admin-ajax.php?action=generate_wpo_wcpdf&document_type=partial_payment_invoice&order_ids=" . $payment_order->get_id()), 'generate_wpo_wcpdf') . '">';
                            $link .= esc_html__('PDF Invoice', 'woocommerce-deposits') . '</a>';

                        }
                    }
                }


            }
        }
        ?>
        <tr class="order_item">
            <td >
                <?php echo $title; ?>
            </td>
            <td>
                <?php echo $payment_id; ?>
            </td>
            <td >
                <?php echo $status; ?>

            </td>
            <td >
                <?php echo wc_price($amount,$price_args); ?>
            </td>
            <?php if(is_account_page() && function_exists('WPO_WCPDF')){
                ?><td> <?php echo $link; ?></td><?php
            }?>
        </tr>
        <?php
    } ?>

    </tbody>

    <tfoot>


    </tfoot>
</table>
