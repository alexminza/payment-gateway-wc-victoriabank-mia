<?php

/**
 * Plugin Name: Payment Gateway for Victoriabank MIA for WooCommerce
 * Description: Accept MIA Instant Payments directly on your store with the Payment Gateway for Victoriabank MIA for WooCommerce.
 * Plugin URI: https://github.com/alexminza/payment-gateway-wc-victoriabank-mia
 * Version: 1.0.2
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: payment-gateway-wc-victoriabank-mia
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Requires at least: 4.8
 * Tested up to: 6.9
 * WC requires at least: 3.3
 * WC tested up to: 10.4.2
 * Requires Plugins: woocommerce
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/payment-gateway-wc-victoriabank-mia
// This plugin is based on PHP SDK for Victoriabank MIA API https://github.com/alexminza/victoriabank-mia-sdk-php (https://packagist.org/packages/alexminza/victoriabank-mia-sdk)

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';

use Victoriabank\VictoriabankMia\VictoriabankMiaClient;

add_action('plugins_loaded', 'victoriabank_mia_init', 0);

function victoriabank_mia_init()
{
    // https://developer.woocommerce.com/docs/features/payments/payment-gateway-plugin-base/
    // load_plugin_textdomain('payment-gateway-wc-victoriabank-mia', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Victoriabank_MIA extends WC_Payment_Gateway
    {
        //region Constants
        const MOD_ID             = 'victoriabank_mia';
        const MOD_TITLE          = 'Victoriabank MIA';
        const MOD_PREFIX         = 'victoriabank_mia_';
        const MOD_VERSION        = '1.0.2';

        const SUPPORTED_CURRENCIES = array('MDL');
        const ORDER_TEMPLATE       = 'Order #%1$s';

        const MOD_QR_ID             =  self::MOD_PREFIX . 'qr_id';
        const MOD_QR_EXTENSION_ID   =  self::MOD_PREFIX . 'qr_extension_id';
        const MOD_QR_URL            =  self::MOD_PREFIX . 'qr_url';
        const MOD_CALLBACK          =  self::MOD_PREFIX . 'callback';
        const MOD_PAYMENT_REFERENCE =  self::MOD_PREFIX . 'payment_reference';

        const DEFAULT_TIMEOUT  = 30; // seconds
        const DEFAULT_VALIDITY = 60; // minutes
        //endregion

        protected $testmode, $debug, $logger, $order_template, $transaction_validity;
        protected $victoriabank_mia_base_url, $victoriabank_mia_username, $victoriabank_mia_password, $victoriabank_mia_certificate, $victoriabank_mia_creditor_account, $victoriabank_mia_company_name;

        public function __construct()
        {
            $this->id                 = self::MOD_ID;
            $this->method_title       = self::MOD_TITLE;
            $this->method_description = 'Payment Gateway for Victoriabank MIA';
            $this->has_fields         = false;
            $this->supports           = array('products', 'refunds');

            $this->init_form_fields();
            $this->init_settings();

            //region Initialize user set variables
            $this->enabled            = $this->get_option('enabled', 'no');
            $this->title              = $this->get_option('title', $this->method_title);
            $this->description        = $this->get_option('description');
            $this->icon               = plugins_url('/assets/img/mia.svg', __FILE__);

            $this->testmode           = wc_string_to_bool($this->get_option('testmode', 'no'));
            $this->debug              = wc_string_to_bool($this->get_option('debug', 'no'));
            $this->logger             = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

            if ($this->testmode) {
                $this->description = $this->get_test_message($this->description);
            }

            $this->order_template       = $this->get_option('order_template', self::ORDER_TEMPLATE);
            $this->transaction_validity = intval($this->get_option('transaction_validity', self::DEFAULT_VALIDITY));

            // https://github.com/alexminza/victoriabank-mia-sdk-php/blob/main/src/VictoriabankMia/VictoriabankMiaClient.php
            $this->victoriabank_mia_base_url    = $this->testmode ? VictoriabankMiaClient::TEST_BASE_URL : VictoriabankMiaClient::DEFAULT_BASE_URL;
            $this->victoriabank_mia_username    = $this->get_option('victoriabank_mia_username');
            $this->victoriabank_mia_password    = $this->get_option('victoriabank_mia_password');
            $this->victoriabank_mia_certificate = $this->get_option('victoriabank_mia_certificate');
            $this->victoriabank_mia_creditor_account = $this->get_option('victoriabank_mia_creditor_account');
            $this->victoriabank_mia_company_name     = $this->get_option('victoriabank_mia_company_name');
            //endregion

            if (is_admin()) {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
            }

            add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled'         => array(
                    'title'       => __('Enable/Disable', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable this gateway', 'payment-gateway-wc-victoriabank-mia'),
                    'default'     => 'yes',
                ),
                'title'           => array(
                    'title'       => __('Title', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'text',
                    'description' => __('Payment method title that the customer will see during checkout.', 'payment-gateway-wc-victoriabank-mia'),
                    'desc_tip'    => true,
                    'default'     => self::MOD_TITLE,
                ),
                'description'     => array(
                    'title'       => __('Description', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description that the customer will see during checkout.', 'payment-gateway-wc-victoriabank-mia'),
                    'desc_tip'    => true,
                    'default'     => '',
                ),

                'testmode'        => array(
                    'title'       => __('Test mode', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'checkbox',
                    'label'       => __('Enabled', 'payment-gateway-wc-victoriabank-mia'),
                    'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'payment-gateway-wc-victoriabank-mia'),
                    'desc_tip'    => true,
                    'default'     => 'no',
                ),
                'debug'           => array(
                    'title'       => __('Debug mode', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable logging', 'payment-gateway-wc-victoriabank-mia'),
                    'default'     => 'no',
                    'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'payment-gateway-wc-victoriabank-mia'), esc_url(self::get_logs_url())),
                    'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'payment-gateway-wc-victoriabank-mia'),
                ),

                'order_template'  => array(
                    'title'       => __('Order description', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'text',
                    /* translators: 1: Example placeholder shown to user, represents Order ID */
                    'description' => __('Format: <code>%1$s</code> - Order ID', 'payment-gateway-wc-victoriabank-mia'),
                    'desc_tip'    => __('Order description that the customer will see on the bank payment page.', 'payment-gateway-wc-victoriabank-mia'),
                    'default'     => self::ORDER_TEMPLATE,
                ),
                'transaction_validity'  => array(
                    'title'       => __('Transaction validity', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => 1,
                        'step' => 1,
                    ),
                    /* translators: 1: Transaction validity in minutes */
                    'description' => sprintf(__('Default: %1$s minutes', 'payment-gateway-wc-victoriabank-mia'), self::DEFAULT_VALIDITY),
                    'default'     => self::DEFAULT_VALIDITY,
                ),

                'connection_settings' => array(
                    'title'       => __('Connection Settings', 'payment-gateway-wc-victoriabank-mia'),
                    'description' => __('Payment gateway connection credentials are provided by the bank.', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'title',
                ),
                'victoriabank_mia_username' => array(
                    'title'       => __('Username', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'text',
                ),
                'victoriabank_mia_password' => array(
                    'title'       => __('Password', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'password',
                ),
                'victoriabank_mia_certificate' => array(
                    'title'       => __('Certificate', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'textarea',
                    'description' => __('Victoriabank Public Key Certificate to validate the authenticity of the payment notifications.', 'payment-gateway-wc-victoriabank-mia'),
                    'desc_tip'    => true,
                    'placeholder' => "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
                    'class'       => 'code',
                ),
                'victoriabank_mia_company_name' => array(
                    'title'       => __('Company Name', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'text',
                ),
                'victoriabank_mia_creditor_account' => array(
                    'title'       => __('Creditor Account', 'payment-gateway-wc-victoriabank-mia'),
                    'type'        => 'text',
                    'description' => __('IBAN', 'payment-gateway-wc-victoriabank-mia'),
                    'placeholder' => 'MD00XX000000000000000000',
                ),

                'payment_notification' => array(
                    'title'       => __('Payment Notification', 'payment-gateway-wc-victoriabank-mia'),
                    'description' => sprintf(
                        '%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code>',
                        esc_html__('Provide this URL to the bank to enable online payment notifications.', 'payment-gateway-wc-victoriabank-mia'),
                        esc_html__('Callback URL', 'payment-gateway-wc-victoriabank-mia'),
                        esc_url($this->get_callback_url())
                    ),
                    'type'        => 'title',
                ),
            );
        }

        public function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true)) {
                return false;
            }

            return true;
        }

        public function is_available()
        {
            if (!$this->is_valid_for_use()) {
                return false;
            }

            if (!$this->check_settings()) {
                return false;
            }

            return parent::is_available();
        }

        public function needs_setup()
        {
            return !$this->check_settings();
        }

        public function admin_options()
        {
            $this->validate_settings();
            $this->display_errors();

            parent::admin_options();
        }

        //region Settings validation
        protected function check_settings()
        {
            return !empty($this->victoriabank_mia_username)
                && !empty($this->victoriabank_mia_password)
                && $this->validate_certificate($this->victoriabank_mia_certificate)
                && !empty($this->victoriabank_mia_company_name)
                && $this->validate_iban($this->victoriabank_mia_creditor_account);
        }

        protected function validate_settings()
        {
            $validate_result = true;

            if (!$this->is_valid_for_use()) {
                $this->add_error(
                    sprintf(
                        '<strong>%1$s: %2$s</strong>. %3$s: %4$s',
                        esc_html__('Unsupported store currency', 'payment-gateway-wc-victoriabank-mia'),
                        esc_html(get_woocommerce_currency()),
                        esc_html__('Supported currencies', 'payment-gateway-wc-victoriabank-mia'),
                        esc_html(join(', ', self::SUPPORTED_CURRENCIES))
                    )
                );

                $validate_result = false;
            }

            if (!$this->check_settings()) {
                /* translators: 1: Plugin installation instructions URL */
                $message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'payment-gateway-wc-victoriabank-mia'), 'https://wordpress.org/plugins/payment-gateway-wc-victoriabank-mia/#installation');
                $this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'payment-gateway-wc-victoriabank-mia'), esc_html__('Not configured', 'payment-gateway-wc-victoriabank-mia'), wp_kses_post($message_instructions)));
                $validate_result = false;
            }

            return $validate_result;
        }

        // https://developer.woocommerce.com/docs/extensions/settings-and-config/implementing-settings/
        public function validate_order_template_field($key, $value)
        {
            if (isset($value) && empty($value)) {
                WC_Admin_Settings::add_error(esc_html__('Order description field must be set.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        public function validate_transaction_validity_field($key, $value)
        {
            if (isset($value) && !$this->validate_transaction_validity($value)) {
                WC_Admin_Settings::add_error(esc_html__('Transaction validity field must be a positive integer.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        public function validate_victoriabank_mia_username_field($key, $value)
        {
            if (isset($value) && empty($value)) {
                WC_Admin_Settings::add_error(esc_html__('Username field must be set.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        public function validate_victoriabank_mia_password_field($key, $value)
        {
            if (isset($value) && empty($value)) {
                WC_Admin_Settings::add_error(esc_html__('Password field must be set.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        public function validate_victoriabank_mia_certificate_field($key, $value)
        {
            if (isset($value) && !$this->validate_certificate($value)) {
                WC_Admin_Settings::add_error(esc_html__('Invalid Certificate field.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        public function validate_victoriabank_mia_company_name_field($key, $value)
        {
            if (isset($value) && empty($value)) {
                WC_Admin_Settings::add_error(esc_html__('Company Name field must be set.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        public function validate_victoriabank_mia_creditor_account_field($key, $value)
        {
            if (isset($value) && !$this->validate_iban($value)) {
                WC_Admin_Settings::add_error(esc_html__('Invalid IBAN field. Must start with MD and have 24 characters.', 'payment-gateway-wc-victoriabank-mia'));
            }

            return $value;
        }

        protected function validate_transaction_validity($value)
        {
            return intval($value) > 0;
        }

        protected function validate_certificate($value)
        {
            return !empty($value)
                && !empty(openssl_pkey_get_public($value));
        }

        protected function validate_iban($value)
        {
            return !empty($value)
                && strlen($value) == 24
                && str_starts_with($value, 'MD');
        }

        protected function logs_admin_website_notice()
        {
            if (current_user_can('manage_woocommerce')) {
                $message = $this->get_logs_admin_message();
                wc_add_notice($message, 'error');
            }
        }

        protected function logs_admin_notice()
        {
            $message = $this->get_logs_admin_message();
            WC_Admin_Notices::add_custom_notice("{$this->id}_logs_admin_notice", $message);
        }

        protected function settings_admin_notice()
        {
            $message = $this->get_settings_admin_message();
            WC_Admin_Notices::add_custom_notice("{$this->id}_settings_admin_notice", $message);
        }

        protected function get_settings_admin_message()
        {
            /* translators: 1: Payment method title, 2: Plugin settings URL */
            $message = sprintf(wp_kses_post(__('%1$s is not properly configured. Verify plugin <a href="%2$s">Connection Settings</a>.', 'payment-gateway-wc-victoriabank-mia')), esc_html($this->method_title), esc_url(self::get_settings_url()));
            return $message;
        }

        protected function get_logs_admin_message()
        {
            /* translators: 1: Payment method title, 2: Plugin settings URL */
            $message = sprintf(wp_kses_post(__('See <a href="%2$s">%1$s settings</a> page for log details and setup instructions.', 'payment-gateway-wc-victoriabank-mia')), esc_html($this->method_title), esc_url(self::get_settings_url()));
            return $message;
        }
        //endregion

        //region Victoriabank MIA
        protected function init_victoriabank_mia_client()
        {
            $options = array(
                'base_uri' => $this->victoriabank_mia_base_url,
                'timeout'  => self::DEFAULT_TIMEOUT,
            );

            if ($this->debug) {
                $log_name = "{$this->id}_guzzle";
                $log_file_name = WC_Log_Handler_File::get_log_file_path($log_name);

                $log = new \Monolog\Logger($log_name);
                $log->pushHandler(new \Monolog\Handler\StreamHandler($log_file_name, \Monolog\Logger::DEBUG));

                $stack = \GuzzleHttp\HandlerStack::create();
                $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

                $options['handler'] = $stack;
            }

            $guzzle_client = new \GuzzleHttp\Client($options);
            $client = new VictoriabankMiaClient($guzzle_client);

            return $client;
        }

        /**
         * @param VictoriabankMiaClient $client
         */
        private function victoriabank_mia_generate_token($client)
        {
            $get_token_response = $client->getToken('password', $this->victoriabank_mia_username, $this->victoriabank_mia_password);
            $access_token = strval($get_token_response['accessToken']);

            return $access_token;
        }

        /**
         * @param VictoriabankMiaClient $client
         * @param string $auth_token
         * @param string $order_id
         * @param string $order_name
         * @param float  $total_amount
         * @param string $currency
         * @param string $creditor_account
         * @param string $company_name
         * @param int    $validity_minutes
         * @return GuzzleHttp\Command\Result
         */
        private function victoriabank_mia_pay($client, $auth_token, $order_id, $order_name, $total_amount, $currency, $creditor_account, $company_name, $validity_minutes)
        {
            $qr_data = array(
                'header' => array(
                    'qrType' => 'DYNM', // Type of QR code: DYNM - Dynamic QR, STAT - Static QR, HYBR - Hybrid QR
                    'amountType' => 'Fixed', // Specifies the type of amount: Fixed - Dynamic QR, Controlled - Static QR, Free - Hybrid QR
                    'pmtContext' => 'e', // Payment context: m - mobile payment, e - e-commerce payment, i - invoice payment, o - other
                ),
                'extension' => array(
                    'creditorAccount' => array(
                        'iban' => $creditor_account,
                    ),
                    'amount' => array(
                        'sum' => $total_amount,
                        'currency' => $currency,
                    ),
                    'dba' => $company_name,
                    'remittanceInfo4Payer' => $order_name,
                    'creditorRef' => strval($order_id),
                    'ttl' => array(
                        'length' => $validity_minutes, // The duration for which the QR code is valid.
                        'units' => 'mm', // The unit of time for the TTL: ss - seconds, mm - minutes
                    ),
                ),
            );

            return $client->createPayeeQr($qr_data, $auth_token);
        }
        //endregion

        //region Payment
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $create_qr_response = null;

            try {
                $client = $this->init_victoriabank_mia_client();
                $auth_token = $this->victoriabank_mia_generate_token($client);

                //region Existing QR
                $qr_extension_id = strval($order->get_meta(self::MOD_QR_EXTENSION_ID, true));
                $qr_url = strval($order->get_meta(self::MOD_QR_URL, true));

                if (!empty($qr_extension_id) && !empty($qr_url)) {
                    $qr_extension_status = $client->getQrExtensionStatus($qr_extension_id, $auth_token);

                    if (!empty($qr_extension_status)) {
                        $this->log(self::print_var($qr_extension_status));

                        $qr_extension_status_value = strval($qr_extension_status['status']);
                        if (strtolower($qr_extension_status_value) === 'active') {
                            $qr_extension_status_ttl = (array) $qr_extension_status['ttl'];
                            $qr_extension_status_ttl_length = intval($qr_extension_status_ttl['length']);
                            $qr_extension_status_ttl_units = strval($qr_extension_status_ttl['units']);

                            $qr_extension_status_ttl_minutes = strtolower($qr_extension_status_ttl_units) === 'mm'
                                ? $qr_extension_status_ttl_length
                                : intdiv($qr_extension_status_ttl_length, 60);

                            if ($qr_extension_status_ttl_minutes >= intdiv($this->transaction_validity, 2)) {
                                return array(
                                    'result'   => 'success',
                                    'redirect' => $qr_url,
                                );
                            }
                        }
                    }
                }
                //endregion

                $create_qr_response = $this->victoriabank_mia_pay(
                    $client,
                    $auth_token,
                    $order_id,
                    $this->get_order_description($order),
                    $order->get_total(),
                    $order->get_currency(),
                    $this->victoriabank_mia_creditor_account,
                    $this->victoriabank_mia_company_name,
                    $this->transaction_validity
                );

                if (!empty($create_qr_response)) {
                    // NOTE: remove redundant large image data
                    $create_qr_response['qrAsImage'] = null;
                }

                $this->log(self::print_var($create_qr_response));
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'order_id' => $order_id,
                    )
                );
            }

            if (!empty($create_qr_response)) {
                $qr_id = strval($create_qr_response['qrHeaderUUID']);
                $qr_extension_id = strval($create_qr_response['qrExtensionUUID']);
                $qr_url = strval($create_qr_response['qrAsText']);

                //region Update order payment transaction metadata
                // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#apis-for-gettingsetting-posts-and-postmeta
                $order->add_meta_data(self::MOD_QR_ID, $qr_id, true);
                $order->add_meta_data(self::MOD_QR_EXTENSION_ID, $qr_extension_id, true);
                $order->add_meta_data(self::MOD_QR_URL, $qr_url, true);
                $order->save();
                //endregion

                /* translators: 1: Order ID, 2: Payment method title, 3: API response details */
                $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s: %3$s', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->method_title, self::print_response_object($create_qr_response)));
                $message = $this->get_test_message($message);
                $this->log($message, WC_Log_Levels::INFO);
                $order->add_order_note($message);

                return array(
                    'result'   => 'success',
                    'redirect' => $qr_url,
                );
            }

            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->method_title));
            $message = $this->get_test_message($message);
            $order->add_order_note($message);
            $this->log($message, WC_Log_Levels::ERROR);

            // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
            if (WC()->is_store_api_request()) {
                throw new Exception(esc_html($message));
            }

            wc_add_notice($message, 'error');
            $this->logs_admin_website_notice();

            return array(
                'result'   => 'failure',
                'messages' => $message,
            );
        }

        public function check_response()
        {
            $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
            if ($request_method === 'GET') {
                /* translators: 1: Payment method title */
                $message = sprintf(__('%1$s Callback URL', 'payment-gateway-wc-victoriabank-mia'), $this->method_title);
                return self::return_response(WP_Http::OK, $message);
            } elseif ($request_method !== 'POST') {
                return self::return_response(WP_Http::METHOD_NOT_ALLOWED);
            }

            //region Validate callback
            $callback_body = null;
            $callback_data = null;

            try {
                $callback_body = wc_clean(file_get_contents('php://input'));
                if (empty($callback_body)) {
                    throw new Exception('Empty callback body');
                }

                $callback_body = trim($callback_body, '"');
                $callback_data = (array) wc_clean(VictoriabankMiaClient::decodeValidateCallback($callback_body, $this->victoriabank_mia_certificate));
                if (empty($callback_data) || !is_array($callback_data)) {
                    throw new Exception('Invalid callback data');
                }

                $this->log(
                    sprintf(__('Payment notification callback', 'payment-gateway-wc-victoriabank-mia')),
                    WC_Log_Levels::DEBUG,
                    array(
                        'callback_body' => $callback_body,
                        'callback_data' => $callback_data,
                    )
                );
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'callback_body' => $callback_body,
                        'callback_data' => $callback_data,
                    )
                );

                return self::return_response(WP_Http::UNAUTHORIZED, 'Invalid callback signature');
            }
            //endregion

            //region Validate signal code
            $callback_signal_code = strval($callback_data['signalCode']);
            if (strtolower($callback_signal_code) !== 'payment') {
                return self::return_response(WP_Http::ACCEPTED);
            }
            //endregion

            //region Validate order ID
            $callback_qr_extension_id = strval($callback_data['qrExtensionUUID']);
            $order = $this->get_order_by_qr_extension_id($callback_qr_extension_id);

            if (empty($order)) {
                /* translators: 1: QR Extension ID, 2: Payment method title */
                $message = sprintf(__('Order not found by QR Extension ID: %1$s received from %2$s.', 'payment-gateway-wc-victoriabank-mia'), $callback_qr_extension_id, $this->method_title);
                $this->log($message, WC_Log_Levels::ERROR);

                return self::return_response(WP_Http::UNPROCESSABLE_ENTITY, 'Order not found');
            }
            //endregion

            //region Check order data
            $callback_data_payment = (array) $callback_data['payment'];
            $callback_data_payment_amount = (array) $callback_data_payment['amount'];
            $callback_amount = floatval($callback_data_payment_amount['sum']);
            $callback_currency = strval($callback_data_payment_amount['currency']);

            $order_id = $order->get_id();
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();

            $order_price = $this->format_price($order_total, $order_currency);
            $callback_price = $this->format_price($callback_amount, $callback_currency);

            if ($order_price !== $callback_price) {
                /* translators: 1: Callback notification price, 2: Order total price */
                $message = sprintf(__('Order amount mismatch: Callback: %1$s, Order: %2$s.', 'payment-gateway-wc-victoriabank-mia'), $callback_price, $order_price);
                $this->log($message, WC_Log_Levels::ERROR);

                return self::return_response(WP_Http::UNPROCESSABLE_ENTITY, 'Order data mismatch');
            }

            if ($order->is_paid()) {
                /* translators: 1: Order ID */
                $message = sprintf(__('Callback order #%1$s already fully paid.', 'payment-gateway-wc-victoriabank-mia'), $order_id);
                $this->log($message, WC_Log_Levels::ERROR);

                return self::return_response(WP_Http::OK, 'Order already fully paid');
            }
            //endregion

            //region Complete order payment
            $callback_payment_reference = strval($callback_data_payment['reference']);
            $callback_payment_transaction_id = VictoriabankMiaClient::getPaymentTransactionId($callback_payment_reference);

            $order->add_meta_data(self::MOD_CALLBACK, $callback_body, true);
            $order->add_meta_data(self::MOD_PAYMENT_REFERENCE, $callback_payment_reference, true);
            $order->save();

            $order->payment_complete($callback_payment_transaction_id);
            //endregion

            /* translators: 1: Order ID, 2: Payment method title, 3: Payment notification callback data */
            $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->method_title, wp_json_encode($callback_data)));
            $message = $this->get_test_message($message);
            $this->log($message, WC_Log_Levels::INFO);
            $order->add_order_note($message);

            return self::return_response(WP_Http::OK);
        }

        public function process_refund($order_id, $amount = null, $reason = '')
        {
            if (!$this->check_settings()) {
                $message = $this->get_settings_admin_message();
                return new WP_Error('check_settings', $message);
            }

            $order = wc_get_order($order_id);
            $payment_reference = strval($order->get_meta(self::MOD_PAYMENT_REFERENCE, true));
            $transaction_id = VictoriabankMiaClient::getPaymentTransactionId($payment_reference);
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();

            //region Validate refund amount
            if (isset($amount) && $amount !== $order_total) {
                /* translators: 1: Payment method title */
                $message = esc_html(sprintf(__('Partial refunds are not currently supported by %1$s.', 'payment-gateway-wc-victoriabank-mia'), $this->method_title));
                $this->log($message, WC_Log_Levels::ERROR);

                return new WP_Error('partial_refund', $message);
            }
            //endregion

            try {
                $client = $this->init_victoriabank_mia_client();
                $auth_token = $this->victoriabank_mia_generate_token($client);

                $client->reverseTransaction($transaction_id, $auth_token);
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'order_id' => $order_id,
                        'amount' => $amount,
                        'reason' => $reason,
                    )
                );

                /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title, 4: Error message */
                $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed: %4$s', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->format_price($order_total, $order_currency), $this->method_title, $ex->getMessage()));
                $message = $this->get_test_message($message);
                $order->add_order_note($message);
                $this->log($message, WC_Log_Levels::ERROR);

                $this->logs_admin_notice();

                return new WP_Error('process_refund', $ex->getMessage());
            }

            /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->format_price($order_total, $order_currency), $this->method_title));
            $message = $this->get_test_message($message);
            $this->log($message, WC_Log_Levels::INFO);
            $order->add_order_note($message);

            return true;
        }
        //endregion

        //region Utility
        /**
         * @param string $qr_extension_id
         */
        protected function get_order_by_qr_extension_id($qr_extension_id)
        {
            // NOTE: Victoriabank MIA API does not currently support passing Order ID for transactions
            // https://stackoverflow.com/questions/71438717/extend-wc-get-orders-with-a-custom-meta-key-and-meta-value
            $args = array(
                'meta_key'   => self::MOD_QR_EXTENSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $qr_extension_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            );

            $orders = wc_get_orders($args);
            $orders_count = count($orders);
            if ($orders_count === 1) {
                return $orders[0];
            } elseif ($orders_count > 1) {
                $log_context = array('orders' => $orders);
                $this->log(sprintf('Duplicate order meta %1$s: %2$s', self::MOD_QR_EXTENSION_ID, $qr_extension_id), WC_Log_Levels::ERROR, $log_context);
            }

            return false;
        }

        /**
         * @param float  $price
         * @param string $currency
         */
        protected function format_price($price, $currency)
        {
            $args = array(
                'currency' => $currency,
                'in_span' => false,
            );

            return wc_price($price, $args);
        }

        /**
         * @param \WC_Order $order
         */
        protected function get_order_description($order)
        {
            $description = sprintf($this->order_template, $order->get_id());
            return apply_filters('victoriabank_mia_order_description', $description, $order);
        }

        /**
         * @param string $message
         */
        protected function get_test_message($message)
        {
            if ($this->testmode) {
                /* translators: 1: Original message */
                $message = esc_html(sprintf(__('TEST: %1$s', 'payment-gateway-wc-victoriabank-mia'), $message));
            }

            return $message;
        }

        /**
         * @param \WC_Order $order
         */
        protected function get_redirect_url($order)
        {
            $redirect_url = $this->get_return_url($order);
            return apply_filters('victoriabank_mia_redirect_url', $redirect_url);
        }

        protected function get_callback_url()
        {
            // https://developer.woocommerce.com/docs/extensions/core-concepts/woocommerce-plugin-api-callback/
            $callback_url = WC()->api_request_url("wc_{$this->id}");
            return apply_filters('victoriabank_mia_callback_url', $callback_url);
        }

        protected static function get_logs_url()
        {
            return add_query_arg(
                array(
                    'page'   => 'wc-status',
                    'tab'    => 'logs',
                    'source' => self::MOD_ID,
                ),
                admin_url('admin.php')
            );
        }

        public static function get_settings_url()
        {
            return add_query_arg(
                array(
                    'page'    => 'wc-settings',
                    'tab'     => 'checkout',
                    'section' => self::MOD_ID,
                ),
                admin_url('admin.php')
            );
        }

        /**
         * @param string $message
         * @param string $level
         * @param array  $additional_context
         */
        protected function log($message, $level = WC_Log_Levels::DEBUG, $additional_context = null)
        {
            // https://developer.woocommerce.com/docs/best-practices/data-management/logging/
            // https://stackoverflow.com/questions/1423157/print-php-call-stack
            $log_context = array('source' => $this->id);
            if (!empty($additional_context)) {
                $log_context = array_merge($log_context, $additional_context);
            }

            $this->logger->log($level, $message, $log_context);
        }

        /**
         * @param string $message
         * @param string $level
         * @param array  $additional_context
         */
        protected static function static_log($message, $level = WC_Log_Levels::DEBUG, $additional_context = null)
        {
            $log_context = array('source' => self::MOD_ID);
            if (!empty($additional_context)) {
                $log_context = array_merge($log_context, $additional_context);
            }

            $logger = wc_get_logger();
            $logger->log($level, $message, $log_context);
        }

        protected static function print_var($expression)
        {
            // https://woocommerce.github.io/code-reference/namespaces/default.html#function_wc_print_r
            return wc_print_r($expression, true);
        }

        /**
         * @param GuzzleHttp\Command\Result $response
         */
        protected static function print_response_object($response)
        {
            if (!empty($response)) {
                return wp_json_encode($response->toArray());
            }

            return '';
        }

        /**
         * @param int    $status_code
         * @param string $response_text
         */
        protected static function return_response($status_code, $response_text = null)
        {
            if (empty($response_text)) {
                $response_text = get_status_header_desc($status_code);
            }

            http_response_code($status_code);
            echo esc_html($response_text);
            exit;
        }
        //endregion

        //region Init
        public static function plugin_action_links($links)
        {
            $plugin_links = array(
                sprintf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url(self::get_settings_url()),
                    esc_html__('Settings', 'payment-gateway-wc-victoriabank-mia')
                ),
            );

            return array_merge($plugin_links, $links);
        }

        public static function add_gateway($methods)
        {
            $methods[] = self::class;
            return $methods;
        }
        //endregion
    }

    //region Init payment gateway
    add_filter('woocommerce_payment_gateways', array(WC_Gateway_Victoriabank_MIA::class, 'add_gateway'));

    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_Gateway_Victoriabank_MIA::class, 'plugin_action_links'));
    }
    //endregion
}

//region Declare WooCommerce compatibility
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            // WooCommerce HPOS compatibility
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#declaring-extension-incompatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

            // WooCommerce Cart Checkout Blocks compatibility
            // https://github.com/woocommerce/woocommerce/pull/36426
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
);
//endregion

//region Register WooCommerce Blocks payment method type
add_action(
    'woocommerce_blocks_loaded',
    function () {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            require_once plugin_dir_path(__FILE__) . 'payment-gateway-wc-victoriabank-mia-wbc.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_Gateway_Victoriabank_MIA_WBC());
                }
            );
        }
    }
);
//endregion
