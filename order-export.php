<?php
/**
 * Plugin Name: LeanCart Order Export
 * Plugin URI:  https://gumgraftcostguide.com/
 * Description: Exports orders to CSV by date range and order status. Ideal for importing into accounting, shipping, and ERP systems.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: order-export
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_ORDER_EXPORT_DEMO_SITE' ) ) {
	define( 'LEANCART_ORDER_EXPORT_DEMO_SITE', 'https://gumgraftcostguide.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Order_Export {

	const SLUG = 'order-export';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Order Export',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_ORDER_EXPORT_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'order-export', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Order Export' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'order-export' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_text( 'delimiter', __('CSV delimiter', 'order-export'), __('Default: comma.', 'order-export'), 'text' );
	}


	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_download' ) );
	}

	public static function admin_page() {
		add_submenu_page( 'woocommerce', __( 'Export orders', 'order-export' ), __( 'Export orders', 'order-export' ), 'manage_woocommerce', 'lc-order-export', array( __CLASS__, 'render' ) );
	}

	public static function maybe_download() {
		if ( ! isset( $_POST['lc_export'] ) ) { return; }
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		check_admin_referer( 'lc_order_export', '_lc_nonce' );
		self::download();
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die(); }
		$from = isset( $_GET['lc_from'] ) ? sanitize_text_field( wp_unslash( $_GET['lc_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = isset( $_GET['lc_to'] ) ? sanitize_text_field( wp_unslash( $_GET['lc_to'] ) ) : gmdate( 'Y-m-d' );
		$status = isset( $_GET['lc_status'] ) ? sanitize_text_field( wp_unslash( $_GET['lc_status'] ) ) : 'any';
		echo '<div class="wrap"><h1>' . esc_html__( 'Export orders', 'order-export' ) . '</h1><form method="post">';
		wp_nonce_field( 'lc_order_export', '_lc_nonce' );
		echo '<p><label>Start <input type="date" name="lc_from" value="' . esc_attr( $from ) . '" /></label> ';
		echo '<label>End <input type="date" name="lc_to" value="' . esc_attr( $to ) . '" /></label> ';
		echo '<label>Status <select name="lc_status"><option value="any">All</option>';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		submit_button( __( 'Download CSV', 'order-export' ), 'primary', 'lc_export' );
		echo '</form></div>';
	}

	private static function download() {
		$from = isset( $_POST['lc_from'] ) ? sanitize_text_field( wp_unslash( $_POST['lc_from'] ) ) : '';
		$to   = isset( $_POST['lc_to'] ) ? sanitize_text_field( wp_unslash( $_POST['lc_to'] ) ) : '';
		$status = isset( $_POST['lc_status'] ) ? sanitize_text_field( wp_unslash( $_POST['lc_status'] ) ) : 'any';
		$args = array( 'limit' => -1, 'status' => $status, 'orderby' => 'ID', 'order' => 'ASC' );
		if ( $from && $to ) { $args['date_created'] = $from . '...' . $to; }
		elseif ( $from ) { $args['date_created'] = '>=' . $from; }
		elseif ( $to ) { $args['date_created'] = '<=' . $to; }
		$orders = wc_get_orders( $args );
		$delim = LeanCart_Framework::setting( 'delimiter', ',' );
		$delim = ( is_string( $delim ) && strlen( $delim ) === 1 ) ? $delim : ',';
		$rows = array( array( 'Order ID', 'Date', 'Status', 'Customer', 'Email', 'SKU', 'Product', 'Qty', 'Line Total', 'Order Total' ) );
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$rows[] = array(
					$order->get_order_number(), $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '', $order->get_status(),
					$order->get_formatted_billing_full_name(), $order->get_billing_email(),
					$product ? $product->get_sku() : '', $item->get_name(), $item->get_quantity(),
					$item->get_total(), $order->get_total(),
				);
			}
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leancart-orders-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$fp = fopen( 'php://output', 'w' );
		fwrite( $fp, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fputcsv( $fp, array_map( array( __CLASS__, 'csv_safe' ), $row ), $delim, '"', '' );
		}
		fclose( $fp );
		exit;
	}

	/** Prevents Excel/Sheets formula injection. */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\s]*[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}


}

LeanCart_Order_Export::init();

