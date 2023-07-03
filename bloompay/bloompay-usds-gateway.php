<?php
/*
Plugin Name: Bloompay USDS Gateway
Plugin URI: https://merchants.bloompay.co.uk/
Description: Payment gateway to enable payments in USDSHARES BEP-20 token for WooCommerce 
Version: 1.0
Author:  Bloomshares Limited
Author URI: https://bloompay.co.uk/
License: GPL2
*/

// Load WooCommerce
if (!class_exists('WC_Payment_Gateway', false)) {
    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    // Load WooCommerce
    if (!defined('WC_ABSPATH')) {
        define('WC_ABSPATH', WP_PLUGIN_DIR . '/' . 'woocommerce' . '/');
    }

    require_once WC_ABSPATH . 'woocommerce.php';
}

// Define the payment gateway class
class Bloompay_USDS_Gateway extends WC_Payment_Gateway
{
    // Constructor function to set up the payment gateway
    public function __construct()
    {
        $this->id = 'bloompay_usds_gateway';
        $this->method_title = 'Bloompay USDS Gateway';
        $this->method_description = 'Payment gateway to enable payments in USDSHARES BEP-20 token';
        $this->has_fields = true;
        $this->supports = array(
            'products'
        );
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        //add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    // Enqueue scripts for handling the iframe communication
    // Unused in version 1.0
    //public function enqueue_scripts()
    //{
    //    wp_enqueue_script('bloompay-usds-gateway', plugins_url('bloompay-usds-gateway.js', __FILE__), array('jquery'), '1.0', true);
    //}

    // Initialize the settings fields
    public function init_form_fields()
    {
        $this->form_fields = array(
            'title' => array(
                'title' => __('Title', 'bloompay-usds-gateway'),
                'type' => 'text',
                'description' => __('This is the title that the user sees during checkout', 'bloompay-usds-gateway'),
                'default' => __('Bloompay USDS Gateway', 'bloompay-usds-gateway'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'bloompay-usds-gateway'),
                'type' => 'textarea',
                'description' => __('This is the description that the user sees during checkout', 'bloompay-usds-gateway'),
                'default' => __('Pay with USDSHARES', 'bloompay-usds-gateway'),
                'desc_tip' => true
            ),
            'service_api_key' => array(
                'title' => __('Merchant API key', 'bloompay-usds-gateway'),
                'type' => 'text',
                'description' => __('API key used to execute privileged functions on gateway', 'bloompay-usds-gateway'),
                'default' => __('ENTER_API_KEY_HERE'),
                'desc_tip' => true
            )
/*            
            // In ver 1.0 we use single official endpoint, uncomment when we create more nodes
            'service_url' => array(
                'title' => __('Service URL', 'bloompay-usds-gateway'),
                'type' => 'text',
                'description' => __('The URL of the USDS payment API that handles the payment', 'bloompay-usds-gateway'),
                'default' => 'https://bloompay.co.uk:443', 'bloompay-usds-gateway'),
                'desc_tip' => true
            ),
*/
/*          // This is static to "810" as of ver 1.0
            'iframe_height' => array(
                'title' => __('iFrame Height', 'bloompay-usds-gateway'),
                'type' => 'number',
                'description' => __('Set the height of the iframe in pixels', 'bloompay-usds-gateway'),
                'default' => '750',
                'desc_tip' => true
            )
*/            
        );
    }


    // Output the payment gateway form
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
/*
        if (!session_id()) {
            session_start();
        }
*/
        //if (!$remote_url = esc_url($this->get_option('service_url'))) {
            $remote_url = 'https://merchants.bloompay.co.uk';
        //}

        $remote_options = array(
            'sslverify' => true,
            'timeout' => 60
        );
        $order_total = WC()->cart->total;

        // Check if transaction ID is already saved in the cookie
        if (isset($_COOKIE['transaction_id'])) {
            $transaction_id = sanitize_text_field($_COOKIE['transaction_id']);        
            $url = $remote_url . '/check_payment?transaction_id=' . urlencode($transaction_id)
                . '&amount=' . urlencode($order_total);
            $response = wp_remote_get($url, $remote_options);

            if (!is_wp_error($response) && $response['response']['code'] == 200) {
                $body = json_decode($response['body'], true);
                $txn_valid = isset($body['status']) && $body['status'] != 'not_found';
            } else {
                $txn_valid = false;
            }
        } else {
            $txn_valid = false;
        }

        if (!$txn_valid) {
            // Request a new transaction ID from the backend server
            $url = $remote_url . '/new_payment'
                . '?api_key=' . $this->get_option('service_api_key')
                . '&amount=' . urlencode($order_total)
                . '&ip=' . urlencode(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
            $response = wp_remote_get($url, $remote_options);

            if (!is_wp_error($response) && $response['response']['code'] == 200) {
                $body = json_decode($response['body'], true);
                if (isset($body['transaction_id'])) {
                    $transaction_id = $body['transaction_id'];
                    setcookie('transaction_id', $transaction_id, time() + (3600 * 24), COOKIEPATH, COOKIE_DOMAIN); // Expires in 24 hours
                } else {
                    // Handle the error if the transaction_id is not in the response
                    wc_add_notice(__('Payment error: ', 'bloompay-usds-gateway') . 'USDS Payment Gateway is currently unavailable.', 'error');
                    return;
                }
            } else {
                // Handle the error if the request to the backend server fails
                wc_add_notice(__('Payment error: ', 'bloompay-usds-gateway') . 'USDS Payment Gateway is currently unavailable.', 'error');
                return;
            }
        }

        $url = $remote_url . '/iframe?transaction_id=' . urlencode($transaction_id);    // . '&amount=' . urlencode($order_total);
        $iframe_height = 810;   //$this->get_option('iframe_height');

        echo '<iframe id="bloompay_usds_gateway_iframe" src="' . $url . '" width="100%" height="' . esc_attr($iframe_height) . '" frameborder="0" scrolling="no" style="border: 1px solid #ccc;"></iframe>';

        echo '<input type="hidden" id="bloompay_usds_gateway_result" name="bloompay_usds_gateway_result" value="">';
        echo '<input type="hidden" id="bloompay_usds_gateway_transaction_id" name="bloompay_usds_gateway_transaction_id" value="' . esc_attr($transaction_id) . '">';
    }

    public function process_payment($order_id)
    {
        //if (!$remote_url = esc_url($this->get_option('service_url'))) {
            $remote_url = 'https://merchants.bloompay.co.uk';
        //}

        $order = wc_get_order($order_id);
        $transaction_id = isset($_POST['bloompay_usds_gateway_transaction_id']) ? sanitize_text_field($_POST['bloompay_usds_gateway_transaction_id']) : '';
        $amount = WC()->cart->total;

        // Check the state of payment
        $url = $remote_url . '/check_payment?transaction_id=' . urlencode($transaction_id) . '&amount=' . urlencode($amount);
        $options = array(
            'sslverify' => true,
            'timeout' => 60
        );
        $response = wp_remote_get($url, $options);

        if (!is_wp_error($response)) {
            if ($response['response']['code'] == 200) {
                $body = json_decode($response['body'], true);
                if ($body['success']) {
                    $order->update_status('processing', __('Payment received', 'bloompay-usds-gateway'));
                    $order->update_meta_data('_transaction_id', $transaction_id);
                    $order->save_meta_data();
                    WC()->cart->empty_cart();

                    // Mark order as processed, prevent re-using same transaction
                    $confirmation_url = $remote_url . '/finish_payment?set_paid=1&transaction_id=' . urlencode($transaction_id) 
                        . '&api_key=' . $this->get_option('service_api_key');
                    wp_remote_get($confirmation_url, $options);

                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
                } else {
                    wc_add_notice(__('Payment error: ', 'bloompay-usds-gateway') . 'Payment was not successful.', 'error');
                    return;
                }
            } else {
                wc_add_notice(__('Payment error: ', 'bloompay-usds-gateway') . 'Invalid response code: ' . $response['response']['code'], 'error');
                return;
            }
        } else {
            wc_add_notice(__('Payment error: ', 'bloompay-usds-gateway') . 'Unable to check payment status. WP Error: ' . $response->get_error_message(), 'error');
            return;
        }
    }
}

// Register the payment gateway with WooCommerce
function add_bloompay_usds_gateway($methods)
{
    $methods[] = 'Bloompay_USDS_Gateway';
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_bloompay_usds_gateway', 99);
