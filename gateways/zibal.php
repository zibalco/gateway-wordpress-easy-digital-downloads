<?php
/**
 * Zibal Gateway for Easy Digital Downloads
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EDD_Zibal_Gateway' ) ) :


class EDD_Zibal_Gateway {
	/**
	 * Gateway keyname
	 *
	 * @var 				string
	 */
	public $keyname;

	/**
	 * Initialize gateway and hook
	 *
	 * @return 				void
	 */
	public function __construct() {
		$this->keyname = 'zibal';

		add_filter( 'edd_payment_gateways', array( $this, 'add' ) );
		add_action( $this->format( 'edd_{key}_cc_form' ), array( $this, 'cc_form' ) );
		add_action( $this->format( 'edd_gateway_{key}' ), array( $this, 'process' ) );
		add_action( $this->format( 'edd_verify_{key}' ), array( $this, 'verify' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );

		add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );

		add_action( 'init', array( $this, 'listen' ) );
	}

	/**
	 * Add gateway to list
	 *
	 * @param 				array $gateways Gateways array
	 * @return 				array
	 */
	public function add( $gateways ) {
		global $edd_options;

		$gateways[ $this->keyname ] = array(
			'checkout_label' 		=>	isset( $edd_options['zibal_label'] ) ? $edd_options['zibal_label'] : 'پرداخت آنلاین زیبال',
			'admin_label' 			=>	'زیبال'
		);

		return $gateways;
	}

	/**
	 * CC Form
	 * We don't need it anyway.
	 *
	 * @return 				bool
	 */
	public function cc_form() {
		return;
	}

	/**
	 * Process the payment
	 * 
	 * @param 				array $purchase_data
	 * @return 				void
	 */
	public function process( $purchase_data ) {
		global $edd_options;
		@ session_start();
		$payment = $this->insert_payment( $purchase_data );

		if ( $payment ) {

			$zibaldirect = ( isset( $edd_options[ $this->keyname . '_zibaldirect' ] ) ? $edd_options[ $this->keyname . '_zibaldirect' ] : false );
			if ( $zibaldirect )
				$redirect = 'https://gateway.zibal.ir/start/%s/direct';
			else
			$redirect = 'https://gateway.zibal.ir/start/%s';

			$merchant = ( isset( $edd_options[ $this->keyname . '_merchant' ] ) ? $edd_options[ $this->keyname . '_merchant' ] : '' );
			$desc = 'پرداخت شماره #' . $payment;
			$callback = add_query_arg( 'verify_' . $this->keyname, '1', get_permalink( $edd_options['success_page'] ) );

			$amount = intval( $purchase_data['price'] );
			if ( edd_get_currency() == 'IRT' )
				$amount = $amount * 10; // Return back to original one.

			$data = json_encode( array(
				'merchant' 			=>	$merchant,
				'amount' 				=>	$amount,
				'description' 			=>	$desc,
				'callbackUrl' 			=>	$callback
			) );

			$ch = curl_init( 'https://gateway.zibal.ir/request' );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'Zibal Rest Api v1' );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json', 'Content-Length: ' . strlen( $data ) ] );
			
			$result = curl_exec( $ch );
			$err = curl_error( $ch );
			if ( $err ) {
				edd_insert_payment_note( $payment, 'کد خطا: CURL#' . $err );
				edd_update_payment_status( $payment, 'failed' );
				edd_set_error( 'zibal_connect_error', 'در اتصال به درگاه مشکلی پیش آمد.' );
				edd_send_back_to_checkout();
				return false;
			}

			$result = json_decode( $result, true );
			curl_close( $ch );

			if ( $result['result'] == 100 ) {
				edd_insert_payment_note( $payment, 'کد تراکنش زیبال: ' . $result['trackId'] );
				edd_update_payment_meta( $payment, 'zibal_track_id', $result['trackId'] );
				$_SESSION['zibal_payment'] = $payment;

				wp_redirect( sprintf( $redirect, $result['trackId'] ) );
			} else {
				edd_insert_payment_note( $payment, 'کدخطا: ' . $result['result'] );
				edd_insert_payment_note( $payment, 'علت خطا: ' . ( $result['message'] ) );
				edd_update_payment_status( $payment, 'failed' );

				edd_set_error( 'zibal_connect_error', 'در اتصال به درگاه مشکلی پیش آمد. علت: ' . ( $result['result'] ) );
				edd_send_back_to_checkout();
			}
		} else {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	}

	/**
	 * Verify the payment
	 *
	 * @return 				void
	 */
	public function verify() {
		global $edd_options;

		if ( isset( $_POST['trackId'] ) ) {
			$authority = sanitize_text_field( $_POST['trackId'] );
			@ session_start();
			$payment = edd_get_payment( $_SESSION['zibal_payment'] );
			unset( $_SESSION['zibal_payment'] );
			if ( ! $payment ) {
				wp_die( 'رکورد پرداخت موردنظر وجود ندارد!' );
			}
			if ( $payment->status == 'complete' ) return false;

			$amount = intval( edd_get_payment_amount( $payment->ID ) );
			if ( edd_get_currency() == 'IRT' )
				$amount = $amount * 10; // Return back to original one.

			$merchant = ( isset( $edd_options[ $this->keyname . '_merchant' ] ) ? $edd_options[ $this->keyname . '_merchant' ] : '' );

			$data = json_encode( array(
				'merchant' 			=>	$merchant,
				'trackId' 				=>	$authority
			) );

			$ch = curl_init( 'https://gateway.zibal.ir/verify' );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'Zibal Rest Api v1' );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json', 'Content-Length: ' . strlen( $data ) ] );
			
			$result = curl_exec( $ch );
			curl_close( $ch );
			$result = json_decode( $result, true );

			edd_empty_cart();

			if ( version_compare( EDD_VERSION, '2.1', '>=' ) )
				edd_set_payment_transaction_id( $payment->ID, $authority );

			if ( $result['result'] == 100 ) {
				edd_insert_payment_note( $payment->ID, 'شماره تراکنش بانکی: ' . $result['trackId'] );
				edd_update_payment_meta( $payment->ID, 'zibal_refnum', $result['trackId'] );
				edd_update_payment_status( $payment->ID, 'publish' );
				edd_send_to_success_page();
			} else {
				edd_update_payment_status( $payment->ID, 'failed' );
				wp_redirect( get_permalink( $edd_options['failure_page'] ) );
			}
		}
	}

	/**
	 * Receipt field for payment
	 *
	 * @param 				object $payment
	 * @return 				void
	 */
	public function receipt( $payment ) {
		$refid = edd_get_payment_meta( $payment->ID, 'zibal_refid' );
		if ( $refid ) {
			echo '<tr class="zibal-ref-id-row ezp-field ehsaan-me"><td><strong>شماره تراکنش بانکی:</strong></td><td>' . $refid . '</td></tr>';
		}
	}

	/**
	 * Gateway settings
	 *
	 * @param 				array $settings
	 * @return 				array
	 */
	public function settings( $settings ) {
		return array_merge( $settings, array(
			$this->keyname . '_header' 		=>	array(
				'id' 			=>	$this->keyname . '_header',
				'type' 			=>	'header',
				'name' 			=>	'<strong>درگاه زیبال</strong>'
			),
			$this->keyname . '_merchant' 		=>	array(
				'id' 			=>	$this->keyname . '_merchant',
				'name' 			=>	'مرچنت‌کد',
				'type' 			=>	'text',
				'size' 			=>	'regular'
			),
			$this->keyname . '_zibaldirect' 		=>	array(
				'id' 			=>	$this->keyname . '_zibaldirect',
				'name' 			=>	'استفاده از زیبال دایرکت (نیاز به تماس با پشتیبانی)',
				'type' 			=>	'checkbox',
				'desc' 			=>	'استفاده از درگاه مستقیم زیبال'
			),
			
			$this->keyname . '_label' 	=>	array(
				'id' 			=>	$this->keyname . '_label',
				'name' 			=>	'نام درگاه در صفحه پرداخت',
				'type' 			=>	'text',
				'size' 			=>	'regular',
				'std' 			=>	'پرداخت آنلاین زیبال'
			)
		) );
	}

	/**
	 * Format a string, replaces {key} with $keyname
	 *
	 * @param 			string $string To format
	 * @return 			string Formatted
	 */
	private function format( $string ) {
		return str_replace( '{key}', $this->keyname, $string );
	}

	/**
	 * Inserts a payment into database
	 *
	 * @param 			array $purchase_data
	 * @return 			int $payment_id
	 */
	private function insert_payment( $purchase_data ) {
		global $edd_options;

		$payment_data = array( 
			'price' => $purchase_data['price'], 
			'date' => $purchase_data['date'], 
			'user_email' => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase_data['downloads'],
			'user_info' => $purchase_data['user_info'],
			'cart_details' => $purchase_data['cart_details'],
			'status' => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment_data );

		return $payment;
	}

	/**
	 * Listen to incoming queries
	 *
	 * @return 			void
	 */
	public function listen() {
		if ( isset( $_GET[ 'verify_' . $this->keyname ] ) && $_GET[ 'verify_' . $this->keyname ] ) {
			do_action( 'edd_verify_' . $this->keyname );
		}
	}

	
}

endif;

new EDD_Zibal_Gateway;
