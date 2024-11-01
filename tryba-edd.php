<?php
/*
    Plugin Name:			Tryba Payment Gateway for Easy Digital Downloads
	Plugin URI: 			http://tryba.io
	Description:            Tryba payment gateway for Easy Digital Downloads
	Version:                1.2
    Author URI:             https://tryba.io/
	Author: 				Tryba
	License:        		GPL-2.0+
	License URI:    		http://www.gnu.org/licenses/gpl-2.0.txt
*/
 

// Tryba Remove CC Form
add_action('edd_tryba_cc_form', '__return_false');

// Registers the gateway
function waf_trybaedd_register_gateway($gateways) {
	$gateways['tryba'] = array('admin_label' => 'Tryba', 'checkout_label' => __('Tryba', 'waf_trybaedd'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'waf_trybaedd_register_gateway');

// Add Payment Gateway section
function waf_trybaedd_register_gateway_section($gateway_sections) {
	$gateway_sections['tryba'] = __('Tryba', 'waf_trybaedd');
	return $gateway_sections;
}
add_filter('edd_settings_sections_gateways', 'waf_trybaedd_register_gateway_section', 1, 1);

// Get currently supported currencies from Tryba endpoint
function waf_trybaedd_get_supported_currencies($string = false) {
	$currency_request = wp_remote_get("https://tryba.io/api/currency-supported2");
	$currency_array = array();
	if (!is_wp_error($currency_request) && 200 == wp_remote_retrieve_response_code($currency_request)) {
		$currencies = json_decode(wp_remote_retrieve_body($currency_request));
		if ($currencies->currency_code && $currencies->currency_name) {
			foreach ($currencies->currency_code as $index => $item) {
                $index = intval(sanitize_text_field($index));
                $item = intval(sanitize_text_field($item));
				if ($string === true) {
					$currency_array[] = sanitize_text_field($currencies->currency_name[$index]);
				} else {
					$currency_array[$item] = sanitize_text_field($currencies->currency_name[$index]);
				}
			}
		}
	}
	if ($string === true) {
		return implode(", ", $currency_array);
	}
	return $currency_array;
}

// Add the settings to the Payment Gateway section
function waf_trybaedd_register_gateway_settings($gateway_settings) {
    $tryba_settings = array (
        'tryba_settings' => array(
            'id'   => 'tryba_settings',
            'name' => '<strong>' . __('Tryba Settings', 'waf_trybaedd') . '</strong>',
            'type' => 'header',
        ),
		'tryba_currency_supported' => array(
			'id' => 'tryba_currency_supported',
			'name' => __('Our Supported Currencies', 'waf_trybaedd'),
			'desc' => esc_attr(waf_trybaedd_get_supported_currencies(true)),
			'type' => 'descriptive_text',
		),
        'tryba_invoice_prefix' => array(
            'id' => 'tryba_invoice_prefix',
            'name' => __('Invoice Prefix', 'waf_trybaedd'),
            'type' => 'text',
            'desc' => __('Please enter a prefix for your invoice numbers. If you use your Tryba account for multiple stores ensure this prefix is unique as Tryba will not allow orders with the same invoice number.', 'waf_tryba'),
            'default' => 'EDD_',
            'desc_tip' => false,
            'size' => 'regular',
        ),
        'tryba_public_key' => array(
            'id' => 'tryba_public_key',
            'name' => __('Public Key', 'waf_trybaedd'),
            'type' => 'text',
            'desc' => __('Required: Enter your Public Key here. You can get your Public Key from <a href="https://tryba.io/user/api">here</a>', 'waf_tryba'),
            'default' => '',
            'desc_tip' => false,
            'size' => 'regular',
        ),
        'tryba_secret_key' => array(
            'id' => 'tryba_secret_key',
            'name' => __('Secret Key', 'waf_trybaedd'),
            'type' => 'text',
            'desc' => __('Required: Enter your Secret Key here. You can get your Secret Key from <a href="https://tryba.io/user/api">here</a>', 'waf_tryba'),
            'default' => '',
            'desc_tip' => false,
            'size' => 'regular',
        )
    );

    $tryba_settings            = apply_filters('edd_tryba_settings', $tryba_settings);
    $gateway_settings['tryba'] = $tryba_settings;

    return $gateway_settings;
}
add_filter('edd_settings_gateways', 'waf_trybaedd_register_gateway_settings', 1, 1);

// Processes the payment
function waf_trybaedd_process_payment($purchase_data) {

    if(!wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway')) {
		wp_die(__('Nonce verification has failed', 'easy-digital-downloads'), __('Error', 'easy-digital-downloads'), array('response' => 403));
	}

	// Get tryba settings 
	$public_key = edd_get_option('tryba_public_key');
	$secret_key = edd_get_option('tryba_secret_key');
    $invoice_prefix = edd_get_option('tryba_invoice_prefix');
	$payment_mode = $purchase_data['post_data']['edd-gateway'];

	// Collect payment data
	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => edd_get_currency(),
		'downloads'    => $purchase_data['downloads'],
		'user_info'    => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'status'       => !empty($purchase_data['buy_now']) ? 'private' : 'pending'
	);

	// Record the pending payment
	$payment = edd_insert_payment($payment_data);

	if ($payment) {
		// Setup Tryba arguments
		$currency = edd_get_currency();
        $currency_array = waf_trybaedd_get_supported_currencies();
        $currency_code = array_search($currency, $currency_array);
        $tx_ref = $invoice_prefix . $payment;
        $amount = $purchase_data['price'];
        $email = $purchase_data['user_email'];
		$callback_url = get_site_url() . "/wp-json/waftryba/v1/process-success?edd_payment_id=" . $payment . "&secret_key=" . $secret_key . "&payment_mode=" . $payment_mode . "&payment_id=";
        $first_name = $purchase_data['user_info']['first_name'];
        $last_name = $purchase_data['user_info']['last_name'];

		// Validate data before send payment tryba request
		$invalid = 0;
		$error_msg = array();
        if (!empty($public_key) && wp_http_validate_url($callback_url)) {
            $public_key = sanitize_text_field($public_key);
            $callback_url = sanitize_url($callback_url);
        } else {
			array_push($error_msg, 'The payment setting of this website is not correct, please contact Administrator');
            $invalid++;
        }
        if (!empty($tx_ref)) {
            $tx_ref = sanitize_text_field($tx_ref);
        } else {
			array_push($error_msg, 'It seems that something is wrong with your order. Please try again');
            $invalid++;
        }
        if (!empty($amount) && is_numeric($amount)) {
            $amount = floatval(sanitize_text_field($amount));
        } else {
			array_push($error_msg, 'It seems that you have submitted an invalid price for this order. Please try again');
            $invalid++;
        }
        if (!empty($email) && is_email($email)) {
            $email = sanitize_email($email);
        } else {
			array_push($error_msg, 'Your email is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($first_name)) {
            $first_name = sanitize_text_field($first_name);
        } else {
			array_push($error_msg, 'Your first name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($last_name)) {
            $last_name = sanitize_text_field($last_name);
        } else {
			array_push($error_msg, 'Your last name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($currency_code) && is_numeric($currency_code)) {
            $currency = sanitize_text_field($currency);
        } else {
			array_push($error_msg, 'The currency code is not valid. Please check and try again');
            $invalid++;
        }

		if ($invalid === 0) {
			$apiUrl = 'https://checkout.tryba.io/api/v1/payment-intent/create';
			$apiResponse = wp_remote_post($apiUrl,
				[
					'method' => 'POST',
					'headers' => [
						'content-type' => 'application/json',
						'PUBLIC-KEY' => $public_key,
					],
					'body' => json_encode(array(
						"amount" => $amount,
						"externalId" => $tx_ref,
						"first_name" => $first_name,
						"last_name" => $last_name,
						"meta" => array(),
						"email" => $email,
						"redirect_url" => $callback_url,
						"currency" => $currency
					))
				]
			);
			if (!is_wp_error($apiResponse)) {
				$apiBody = json_decode(wp_remote_retrieve_body($apiResponse));
				$external_url = $apiBody->externalUrl;
				wp_redirect($external_url);
				exit;
			} else {
				edd_set_error('payment_declined', implode(", ", $apiResponse->get_error_message()));
				edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
			}
		} else {
			edd_set_error('payment_declined', implode(", ",$error_msg));
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		}
	} else {
		edd_record_gateway_error(__('Payment Error', 'easy-digital-downloads'), sprintf(__( 'Payment creation failed while processing a manual (free or test) purchase. Payment data: %s', 'easy-digital-downloads'), json_encode($payment_data)), $payment);
		// If errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	}
}
add_action('edd_gateway_tryba', 'waf_trybaedd_process_payment');

// Set transaction_id for first time payment
function waf_trybaedd_get_payment_transaction_id($payment_id) {
	$transaction_id = '';
	return apply_filters('edd_paypal_set_payment_transaction_id', $transaction_id, $payment_id);
}
add_filter('edd_get_payment_transaction_id-tryba', 'waf_trybaedd_get_payment_transaction_id', 10, 1);

// Register process success rest api
add_action('rest_api_init', 'waf_trybaedd_add_callback_url_endpoint_process_success');

function waf_trybaedd_add_callback_url_endpoint_process_success() {
	register_rest_route(
		'waftryba/v1/',
		'process-success',
		array(
			'methods' => 'GET',
			'callback' => 'waf_trybaedd_process_success'
		)
	);
}

// Callback function of process success rest api
function waf_trybaedd_process_success($request_data) {

	$parameters = $request_data->get_params();
	$payment_mode = $parameters['payment_mode'];

	if ($parameters['payment_id']) {
		$edd_payment_id = intval(sanitize_text_field($parameters['edd_payment_id']));
		$secret_key = $parameters['secret_key'];
		$payment = new EDD_Payment( $edd_payment_id );

		// Verify tryba payment
		$tryba_payment_id = str_replace('?payment_id=', '', sanitize_text_field($parameters['payment_id']));
		$tryba_request = wp_remote_get(
			'https://checkout.tryba.io/api/v1/payment-intent/' . $tryba_payment_id,
			[
				'method' => 'GET',
				'headers' => [
					'content-type' => 'application/json',
					'SECRET-KEY' => $secret_key,
				]
			]
		);

		if (!is_wp_error($tryba_request) && 200 == wp_remote_retrieve_response_code($tryba_request)) {
			$tryba_payment = json_decode(wp_remote_retrieve_body($tryba_request));
			$status = $tryba_payment->status;
			$payment_total = $payment->total;
			$amount_paid = $tryba_payment->amount;

			if ($status === "SUCCESS") {
				// Empty the shopping cart
				edd_empty_cart();

				if ($amount_paid < $payment_total) {
					// Mark as pending
					edd_update_payment_status( $edd_payment_id, 'pending' );
					edd_insert_payment_note( $edd_payment_id, __("Amount paid is not the same as the total order amount.", 'easy-digital-downloads'));
				} else {
					//Complete payment
					edd_update_payment_status($edd_payment_id, 'publish');
					edd_set_payment_transaction_id( $edd_payment_id, $tryba_payment_id );
					edd_insert_payment_note( $edd_payment_id, __("Payment via Tryba successful with Reference ID: " . $tryba_payment_id, 'easy-digital-downloads'));
				}

				// Get the success url
				$return_url = add_query_arg(
								array(
									'payment-confirmation' => 'tryba',
									'payment-id' => $edd_payment_id
								), 
								get_permalink(edd_get_option('success_page', false))
							);

				wp_redirect($return_url);
				die();
			} elseif ($status === "CANCELLED") {
				edd_update_payment_status($edd_payment_id, 'failed');
				edd_insert_payment_note( $edd_payment_id, __("Payment was canceled.", 'easy-digital-downloads'));
				edd_set_error( 'payment_declined', 'Payment was canceled.');
				edd_send_back_to_checkout('?payment-mode=' . $payment_mode);
				die();
			} else {
				edd_update_payment_status($edd_payment_id, 'failed');
				edd_insert_payment_note( $edd_payment_id, __("Payment was declined by Tryba.", 'easy-digital-downloads'));
				edd_set_error( 'payment_declined', 'Payment was declined by Tryba.');
				edd_send_back_to_checkout('?payment-mode=' . $payment_mode);
				die();
			}
		}
	}
	die();
}