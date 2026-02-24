<?php

/**
 * @package payment-gateway-wc-victoriabank-mia
 */

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

use Victoriabank\VictoriabankMia\VictoriabankMiaClient;

class WC_Gateway_Victoriabank_MIA extends WC_Payment_Gateway_Base
{
    //region Constants
    const MOD_ID          = 'victoriabank_mia';
    const MOD_TEXT_DOMAIN = 'payment-gateway-wc-victoriabank-mia';
    const MOD_PREFIX      = 'victoriabank_mia_';
    const MOD_TITLE       = 'Victoriabank MIA';
    const MOD_VERSION     = '1.0.6';
    const MOD_PLUGIN_FILE = VICTORIABANK_MIA_MOD_PLUGIN_FILE;

    const SUPPORTED_CURRENCIES = array('MDL');

    const MOD_QR_ID             = self::MOD_PREFIX . 'qr_id';
    const MOD_QR_EXTENSION_ID   = self::MOD_PREFIX . 'qr_extension_id';
    const MOD_QR_URL            = self::MOD_PREFIX . 'qr_url';
    const MOD_PAYMENT_CALLBACK  = self::MOD_PREFIX . 'payment_callback';
    const MOD_PAYMENT_RECEIPT   = self::MOD_PREFIX . 'payment_receipt';
    const MOD_PAYMENT_REFERENCE = self::MOD_PREFIX . 'payment_reference';

    const MOD_ACTION_CHECK_PAYMENT = self::MOD_PREFIX . 'check_payment';

    const MOD_QR_EXPIRES_AT = self::MOD_PREFIX . 'qr_expires_at';

    /**
     * Default API request timeout (seconds).
     */
    const DEFAULT_TIMEOUT = 30;

    /**
     * Default transaction validity (minutes).
     */
    const DEFAULT_VALIDITY = 360;

    const MIN_VALIDITY     = 1;    // minutes
    const MAX_VALIDITY     = 1440; // minutes

    const REDIRECT_MODE_THANKYOU = 'thankyou';
    const REDIRECT_MODE_QR_URL   = 'qr_url';
    //endregion

    protected $transaction_validity;
    protected $redirect_mode;
    protected $victoriabank_mia_base_url, $victoriabank_mia_username, $victoriabank_mia_password, $victoriabank_mia_certificate;
    protected $victoriabank_mia_creditor_account, $victoriabank_mia_company_name;

    public function __construct()
    {
        $this->id                 = self::MOD_ID;
        $this->method_title       = self::MOD_TITLE;
        $this->method_description = __('Accept MIA Instant Payments through Victoriabank.', 'payment-gateway-wc-victoriabank-mia');
        $this->has_fields         = false;
        $this->supports           = array('products', 'refunds');

        //region Initialize settings
        $this->init_form_fields();
        $this->init_settings();

        parent::__construct();

        $this->icon = plugins_url('assets/img/mia.svg', self::MOD_PLUGIN_FILE);
        $this->transaction_validity = intval($this->get_option('transaction_validity', self::DEFAULT_VALIDITY));
        $this->redirect_mode        = $this->get_option('redirect_mode', self::REDIRECT_MODE_THANKYOU);

        // https://github.com/alexminza/victoriabank-mia-sdk-php/blob/main/src/VictoriabankMia/VictoriabankMiaClient.php
        $this->victoriabank_mia_base_url         = $this->testmode ? VictoriabankMiaClient::TEST_BASE_URL : VictoriabankMiaClient::DEFAULT_BASE_URL;
        $this->victoriabank_mia_username         = $this->get_option('victoriabank_mia_username');
        $this->victoriabank_mia_password         = $this->get_option('victoriabank_mia_password');
        $this->victoriabank_mia_certificate      = $this->get_option('victoriabank_mia_certificate');
        $this->victoriabank_mia_creditor_account = $this->get_option('victoriabank_mia_creditor_account');
        $this->victoriabank_mia_company_name     = $this->get_option('victoriabank_mia_company_name');

        if (is_admin()) {
            add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));

            add_filter('woocommerce_order_actions', array($this, 'order_actions'), 10, 2);
            add_action('woocommerce_order_action_' . self::MOD_ACTION_CHECK_PAYMENT, array($this, 'action_check_payment'));
        }
        //endregion

        add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));

        add_action("woocommerce_thankyou_{$this->id}", array($this, 'thankyou_page'));
        add_filter('woocommerce_thankyou_order_received_text', array($this, 'thankyou_order_received_text'), 10, 2);

        add_action('wp_ajax_' . self::MOD_PREFIX . 'check_order_status', array($this, 'ajax_check_order_status'));
        add_action('wp_ajax_nopriv_' . self::MOD_PREFIX . 'check_order_status', array($this, 'ajax_check_order_status'));
    }

    public function init_form_fields()
    {
        $blog_info_name = get_bloginfo('name');

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
                'default'     => $this->get_method_title(),
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'description'     => array(
                'title'       => __('Description', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see during checkout.', 'payment-gateway-wc-victoriabank-mia'),
                'desc_tip'    => true,
                'default'     => __('Pay instantly by scanning the QR code using your bank\'s mobile application.', 'payment-gateway-wc-victoriabank-mia'),
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
                'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'payment-gateway-wc-victoriabank-mia'), esc_url(self::get_logs_url())),
                'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'payment-gateway-wc-victoriabank-mia'),
                'default'     => 'no',
            ),

            'order_template'  => array(
                'title'       => __('Order description', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'text',
                /* translators: 1: Example placeholder shown to user, represents Order ID */
                'description' => __('Format: <code>%1$s</code> - Order ID', 'payment-gateway-wc-victoriabank-mia'),
                'desc_tip'    => __('Order description that the customer will see in the app during payment.', 'payment-gateway-wc-victoriabank-mia'),
                'default'     => self::ORDER_TEMPLATE,
                'custom_attributes' => array(
                    'required' => 'required',
                    'minlength' => 2,
                    'maxlength' => 35,
                ),
            ),
            'transaction_validity'  => array(
                'title'       => __('Transaction validity', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'number',
                /* translators: 1: Transaction validity in minutes */
                'description' => sprintf(__('Default: %1$s minutes', 'payment-gateway-wc-victoriabank-mia'), self::DEFAULT_VALIDITY),
                'desc_tip'    => __('QR code validity time in minutes.', 'payment-gateway-wc-victoriabank-mia'),
                'default'     => self::DEFAULT_VALIDITY,
                'custom_attributes' => array(
                    'min'      => self::MIN_VALIDITY,
                    'step'     => 1,
                    'max'      => self::MAX_VALIDITY,
                    'required' => 'required',
                ),
            ),
            'redirect_mode' => array(
                'title'       => __('After payment redirect', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'select',
                'description' => __('Where to redirect the customer after the QR code is generated.', 'payment-gateway-wc-victoriabank-mia'),
                'desc_tip'    => true,
                'default'     => self::REDIRECT_MODE_THANKYOU,
                'options'     => array(
                    self::REDIRECT_MODE_THANKYOU => __('Order thank you page (with QR code and payment status polling)', 'payment-gateway-wc-victoriabank-mia'),
                    self::REDIRECT_MODE_QR_URL   => __('QR code URL (direct deep-link to banking app)', 'payment-gateway-wc-victoriabank-mia'),
                ),
            ),

            'connection_settings' => array(
                'title'       => __('Connection Settings', 'payment-gateway-wc-victoriabank-mia'),
                'description' => __('Payment gateway connection credentials are provided by the bank.', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'title',
            ),
            'victoriabank_mia_username' => array(
                'title'       => __('Username', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'text',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'victoriabank_mia_password' => array(
                'title'       => __('Password', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'password',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'victoriabank_mia_certificate' => array(
                'title'       => __('Bank certificate', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'textarea',
                'description' => 'VBCA.crt',
                'desc_tip'    => __('Victoriabank Public Key Certificate to validate the authenticity of the payment notifications.', 'payment-gateway-wc-victoriabank-mia'),
                'placeholder' => "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
                'class'       => 'code',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'victoriabank_mia_company_name' => array(
                'title'       => __('Company name', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'text',
                'description' => $blog_info_name,
                'desc_tip'    => __('Commercial name that the customer will see in the app during payment.', 'payment-gateway-wc-victoriabank-mia'),
                'default'     => $blog_info_name,
                'custom_attributes' => array(
                    'required' => 'required',
                    'minlength' => 2,
                    'maxlength' => 25,
                ),
            ),
            'victoriabank_mia_creditor_account' => array(
                'title'       => __('Creditor account', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'text',
                'description' => __('IBAN', 'payment-gateway-wc-victoriabank-mia'),
                'desc_tip'    => __('IBAN account for receiving payments.', 'payment-gateway-wc-victoriabank-mia'),
                'placeholder' => 'MD00XX000000000000000000',
                'custom_attributes' => array(
                    'required'  => 'required',
                    'minlength' => 24,
                    'maxlength' => 24,
                    'pattern'   => '^MD.*',
                ),
            ),

            'payment_notification' => array(
                'title'       => __('Payment Notification', 'payment-gateway-wc-victoriabank-mia'),
                'type'        => 'title',
                'description' => sprintf(
                    '%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code>',
                    esc_html__('Provide this URL to the bank to enable online payment notifications.', 'payment-gateway-wc-victoriabank-mia'),
                    esc_html__('Callback URL', 'payment-gateway-wc-victoriabank-mia'),
                    esc_url($this->get_callback_url())
                ),
            ),
        );
    }

    //region Settings validation
    protected function check_settings()
    {
        return parent::check_settings()
            && !empty($this->victoriabank_mia_username)
            && !empty($this->victoriabank_mia_password)
            && $this->validate_public_key($this->victoriabank_mia_certificate)
            && !empty($this->victoriabank_mia_company_name)
            && $this->validate_iban($this->victoriabank_mia_creditor_account);
    }

    public function validate_order_template_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_transaction_validity_field($key, $value)
    {
        if (isset($value) && !$this->validate_transaction_validity($value)) {
            /* translators: 1: Field label, 2: Min value, 3: Max value */
            $this->add_error(esc_html(sprintf(__('%1$s field must be an integer between %2$d and %3$d.', 'payment-gateway-wc-victoriabank-mia'), $this->get_settings_field_label($key), self::MIN_VALIDITY, self::MAX_VALIDITY)));
        }

        return $value;
    }

    public function validate_victoriabank_mia_username_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_victoriabank_mia_password_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_victoriabank_mia_certificate_field($key, $value)
    {
        if (isset($value) && !$this->validate_public_key($value)) {
            /* translators: 1: Field label */
            $this->add_error(esc_html(sprintf(__('Invalid %1$s field.', 'payment-gateway-wc-victoriabank-mia'), $this->get_settings_field_label($key))));
        }

        return $value;
    }

    public function validate_victoriabank_mia_company_name_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_victoriabank_mia_creditor_account_field($key, $value)
    {
        if (isset($value) && !$this->validate_iban($value)) {
            /* translators: 1: Field label */
            $this->add_error(esc_html(sprintf(__('Invalid %1$s field. Must start with MD and have 24 characters.', 'payment-gateway-wc-victoriabank-mia'), $this->get_settings_field_label($key))));
        }

        return $value;
    }

    protected function validate_transaction_validity($value)
    {
        $transaction_validity = intval($value);
        return $transaction_validity >= self::MIN_VALIDITY
            && $transaction_validity <= self::MAX_VALIDITY;
    }

    protected function validate_iban($value)
    {
        return !empty($value)
            && strlen($value) === 24
            && substr($value, 0, 2) === 'MD';
    }
    //endregion

    //region QR
    /**
     * @param string $thank_you_title
     * @param \WC_Order $order
     */
    public function thankyou_order_received_text($thank_you_title, $order)
    {
        // https://rudrastyh.com/woocommerce/thank-you-page.html
        if (!empty($order)) {
            if (!$order->is_paid() && $order->get_payment_method() === $this->id) {
                $thank_you_title .= '<br />' . __('This order has a pending payment. Follow the instructions below.', 'payment-gateway-wc-victoriabank-mia');
            }
        }

        return wp_kses_post($thank_you_title);
    }

    /**
     * @param int $order_id
     */
    public function thankyou_page($order_id)
    {
        $order     = wc_get_order($order_id);
        $is_paid   = $order->is_paid();
        $is_mobile = wp_is_mobile();

        $qr_url = '';
        if (!$is_paid) {
            $qr_url = $order->get_meta(self::MOD_QR_URL, true);
            if (empty($qr_url)) {
                /* translators: 1: Order ID, 2: Meta field name */
                $message = sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-victoriabank-mia'), $order_id, self::MOD_QR_URL);
                $this->log($message, \WC_Log_Levels::ERROR);
                return;
            }
        }

        wc_get_template(
            'payment/victoriabank-mia-thankyou.php',
            array(
                'gateway_title'     => $this->title,
                'gateway_icon'      => $this->icon,
                'fieldset_id'       => "{$this->id}-order-qrcode",
                'success_id'        => "{$this->id}-success-message",
                'expired_id'        => "{$this->id}-expired-message",
                'qr_section_id'     => "{$this->id}-qr-section",
                'qr_code_js_div_id' => "{$this->id}-order-qrcode-js",
                'countdown_id'      => "{$this->id}-countdown",
                'deep_link_id'      => "{$this->id}-deep-link",
                'is_paid'           => $is_paid,
                'is_mobile'         => $is_mobile,
                'qr_url'            => $qr_url,
                'pay_url'           => $order->get_checkout_payment_url(true),
                'qr_code_title'     => $is_mobile ? __('Select & Pay', 'payment-gateway-wc-victoriabank-mia') : __('Scan & Pay', 'payment-gateway-wc-victoriabank-mia'),
                'qr_code_text'      => $is_mobile
                    ? __('Choose the financial app from the list by pressing the button below.', 'payment-gateway-wc-victoriabank-mia')
                    : __('Scan this QR code with your phone camera or from your financial app and complete the payment.', 'payment-gateway-wc-victoriabank-mia'),
                'qr_code_url_text'  => __('Banks list', 'payment-gateway-wc-victoriabank-mia'),
                'validity_text'     => __('QR code valid for', 'payment-gateway-wc-victoriabank-mia'),
                'expired_text'      => __('The QR code has expired.', 'payment-gateway-wc-victoriabank-mia'),
                'retry_text'        => __('Generate new QR code', 'payment-gateway-wc-victoriabank-mia'),
                'success_text'      => __('Payment received. Thank you for your order!', 'payment-gateway-wc-victoriabank-mia'),
                'paid_text'         => __('This order is fully paid.', 'payment-gateway-wc-victoriabank-mia'),
            ),
            '',
            plugin_dir_path(self::MOD_PLUGIN_FILE) . 'templates/'
        );

        if ($is_paid) {
            return;
        }

        $expires_at = intval($order->get_meta(self::MOD_QR_EXPIRES_AT, true));

        if (!$is_mobile) {
            // https://cdnjs.com/libraries/qrcodejs
            $js_div_id = wp_json_encode("{$this->id}-order-qrcode-js");
            $js_text   = wp_json_encode($qr_url);

            wp_enqueue_script('qrcodejs', plugins_url('/assets/js/qrcodejs/qrcode.min.js', self::MOD_PLUGIN_FILE), array(), '1.0.0', true);
            wp_add_inline_script(
                'qrcodejs',
                "new QRCode({$js_div_id}, { text: {$js_text}, width: 200, height: 200 });"
            );
        }

        $check_nonce = wp_create_nonce("{$this->id}-check-order-status-{$order_id}");

        wp_enqueue_script(
            'victoriabank-mia-thankyou',
            plugins_url('/assets/js/thankyou.js', self::MOD_PLUGIN_FILE),
            $is_mobile ? array() : array('qrcodejs'),
            self::MOD_VERSION,
            true
        );

        wp_localize_script(
            'victoriabank-mia-thankyou',
            'victoriabank_mia_thankyou',
            array(
                'order_id'      => $order_id,
                'nonce'         => $check_nonce,
                'expires_at'    => $expires_at,
                'ajax_url'      => admin_url('admin-ajax.php'),
                'action_status' => self::MOD_PREFIX . 'check_order_status',
            )
        );
    }

    public function ajax_check_order_status()
    {
        $order_id = isset($_POST['order_id']) ? intval(wp_unslash($_POST['order_id'])) : 0;
        $nonce    = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        $expected_nonce = "{$this->id}-check-order-status-{$order_id}";
        if (empty($order_id) || !wp_verify_nonce($nonce, $expected_nonce)) {
            wp_send_json_error(
                array('message' => __('Invalid request', 'payment-gateway-wc-victoriabank-mia')),
                \WP_Http::UNPROCESSABLE_ENTITY
            );
        }

        $order = wc_get_order($order_id);
        if (empty($order)) {
            wp_send_json_error(
                array('message' => __('Order not found', 'payment-gateway-wc-victoriabank-mia')),
                \WP_Http::UNPROCESSABLE_ENTITY
            );
        }

        wp_send_json_success(array('paid' => $order->is_paid()));
    }
    //endregion

    //region Victoriabank MIA
    /**
     * @link https://github.com/alexminza/victoriabank-mia-sdk-php/blob/main/README.md#getting-started
     */
    protected function init_victoriabank_mia_client()
    {
        $options = array(
            'base_uri' => $this->victoriabank_mia_base_url,
            'timeout'  => self::DEFAULT_TIMEOUT,
        );

        if ($this->debug) {
            $log_name = "{$this->id}_guzzle";
            $log_file_name = \WC_Log_Handler_File::get_log_file_path($log_name);

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
     * @link https://github.com/alexminza/victoriabank-mia-sdk-php/blob/main/README.md#get-access-token-with-username-and-password
     * @link https://test-ipspj.victoriabank.md/index.html#operations-Token-post_identity_token
     */
    private function victoriabank_mia_generate_token(VictoriabankMiaClient $client)
    {
        $get_token_response = $client->getToken('password', $this->victoriabank_mia_username, $this->victoriabank_mia_password);

        if (empty($get_token_response) || !isset($get_token_response['accessToken'])) {
            throw new \Exception('Failed to obtain Victoriabank MIA QR API access token');
        }

        $access_token = strval($get_token_response['accessToken']);
        return $access_token;
    }

    /**
     * @link https://github.com/alexminza/victoriabank-mia-sdk-php/blob/main/README.md#create-a-dynamic-order-payment-qr
     * @link https://test-ipspj.victoriabank.md/index.html#operations-Qr-post_api_v1_qr
     */
    private function victoriabank_mia_pay(VictoriabankMiaClient $client, string $auth_token, \WC_Order $order)
    {
        $qr_data = array(
            'header' => array(
                'qrType' => 'DYNM',
                'amountType' => 'Fixed',
                'pmtContext' => 'e',
            ),
            'extension' => array(
                'creditorAccount' => array(
                    'iban' => $this->victoriabank_mia_creditor_account,
                ),
                'amount' => array(
                    'sum' => $order->get_total(),
                    'currency' => $order->get_currency(),
                ),
                'dba' => $this->victoriabank_mia_company_name,
                'remittanceInfo4Payer' => $this->get_order_description($order),
                'creditorRef' => strval($order->get_id()),
                'ttl' => array(
                    'length' => $this->transaction_validity,
                    'units' => 'mm',
                ),
            ),
        );

        return $client->createPayeeQr($qr_data, $auth_token);
    }

    /**
     * @link https://test-ipspj.victoriabank.md/index.html#operations-Qr-get_api_v1_qr_extensions__qrExtensionUUID__status
     */
    private function victoriabank_mia_qr_active(VictoriabankMiaClient $client, string $auth_token, string $qr_extension_id)
    {
        $qr_extension_status = $client->getQrExtensionStatus($qr_extension_id, $auth_token);

        if (!empty($qr_extension_status)) {
            $qr_extension_status_value = strval($qr_extension_status['status']);

            if (strtolower($qr_extension_status_value) === 'active') {
                $qr_extension_status_ttl = (array) $qr_extension_status['ttl'];
                $qr_extension_status_ttl_length = intval($qr_extension_status_ttl['length']);
                $qr_extension_status_ttl_units = strval($qr_extension_status_ttl['units']);

                $min_validity_seconds = $this->transaction_validity * 60 / 2;
                $remaining_seconds = strtolower($qr_extension_status_ttl_units) === 'mm'
                    ? $qr_extension_status_ttl_length * 60
                    : $qr_extension_status_ttl_length;

                return $remaining_seconds >= $min_validity_seconds;
            }
        }

        return false;
    }
    //endregion

    //region Payment
    protected function get_payment_redirect_url(\WC_Order $order, string $qr_url)
    {
        if (self::REDIRECT_MODE_QR_URL === $this->redirect_mode) {
            return $qr_url;
        }

        return $this->get_redirect_url($order);
    }

    /**
     * @param int $order_id
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $create_qr_response = null;

        try {
            $client = $this->init_victoriabank_mia_client();
            $auth_token = $this->victoriabank_mia_generate_token($client);

            //region Existing QR
            try {
                $qr_extension_id = strval($order->get_meta(self::MOD_QR_EXTENSION_ID, true));
                $qr_url = strval($order->get_meta(self::MOD_QR_URL, true));

                if (!empty($qr_extension_id) && !empty($qr_url)) {
                    if ($this->victoriabank_mia_qr_active($client, $auth_token, $qr_extension_id)) {
                        return array(
                            'result'   => 'success',
                            'redirect' => $this->get_payment_redirect_url($order, $qr_url),
                        );
                    }
                }
            } catch (\Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    \WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'response' => self::get_guzzle_error_response_body($ex),
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }
            //endregion

            $create_qr_response = $this->victoriabank_mia_pay($client, $auth_token, $order);

            if (!empty($create_qr_response)) {
                // NOTE: remove redundant large image data
                $create_qr_response['qrAsImage'] = null;
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'response' => self::get_guzzle_error_response_body($ex),
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!empty($create_qr_response)) {
            $qr_id = strval($create_qr_response['qrHeaderUUID']);
            $qr_extension_id = strval($create_qr_response['qrExtensionUUID']);
            $qr_url = strval($create_qr_response['qrAsText']);

            //region Update order payment transaction metadata
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#apis-for-gettingsetting-posts-and-postmeta
            $order->update_meta_data(self::MOD_QR_ID, $qr_id);
            $order->update_meta_data(self::MOD_QR_EXTENSION_ID, $qr_extension_id);
            $order->update_meta_data(self::MOD_QR_URL, $qr_url);
            $order->update_meta_data(self::MOD_QR_EXPIRES_AT, time() + ($this->transaction_validity * 60));
            $order->save();
            //endregion

            /* translators: 1: Order ID, 2: Payment method title, 3: API response details */
            $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s: %3$s', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->get_method_title(), $qr_extension_id));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'create_qr_response' => $create_qr_response->toArray(),
                )
            );

            $order->add_order_note($message);

            return array(
                'result'   => 'success',
                'redirect' => $this->get_payment_redirect_url($order, $qr_url),
            );
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log($message, \WC_Log_Levels::ERROR);
        $order->add_order_note($message);

        wc_add_notice($message, 'error');
        $this->logs_admin_website_notice();

        // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
        // https://github.com/woocommerce/woocommerce/pull/53671
        return array(
            'result'  => 'failure',
            'message' => $message,
        );
    }

    public function check_response()
    {
        $this->log_request(__FUNCTION__);

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ('GET' === $request_method) {
            /* translators: 1: Payment method title */
            $message = sprintf(__('%1$s Callback URL', 'payment-gateway-wc-victoriabank-mia'), $this->get_method_title());
            return self::return_response(\WP_Http::OK, $message);
        } elseif ('POST' !== $request_method) {
            return self::return_response(\WP_Http::METHOD_NOT_ALLOWED);
        }

        //region Validate callback
        $callback_body = null;
        $callback_data = null;

        try {
            $callback_body = file_get_contents('php://input');
            if (empty($callback_body)) {
                throw new \Exception('Empty callback body');
            }

            $callback_body = trim($callback_body, '"');
            $callback_data = (array) wc_clean(VictoriabankMiaClient::decodeValidateCallback($callback_body, $this->victoriabank_mia_certificate));
            if (empty($callback_data) || !is_array($callback_data)) {
                throw new \Exception('Invalid callback data');
            }

            $message = __('Payment notification callback', 'payment-gateway-wc-victoriabank-mia');
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'callback_body' => $callback_body,
                    'callback_data' => $callback_data,
                )
            );
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'callback_body' => $callback_body,
                    'callback_data' => $callback_data,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            return self::return_response(\WP_Http::UNAUTHORIZED, 'Invalid callback signature');
        }
        //endregion

        //region Validate signal code
        $callback_signal_code = strval($callback_data['signalCode']);
        if (strtolower($callback_signal_code) !== 'payment') {
            return self::return_response(\WP_Http::ACCEPTED);
        }
        //endregion

        //region Validate order ID
        $callback_qr_extension_id = strval($callback_data['qrExtensionUUID']);
        $order = $this->get_order_by_meta_field_value(self::MOD_QR_EXTENSION_ID, $callback_qr_extension_id);

        if (empty($order)) {
            /* translators: 1: QR Extension ID, 2: Payment method title */
            $message = sprintf(__('Order not found by QR Extension ID: %1$s received from %2$s.', 'payment-gateway-wc-victoriabank-mia'), $callback_qr_extension_id, $this->get_method_title());
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'callback_data' => $callback_data,
                )
            );

            return self::return_response(\WP_Http::UNPROCESSABLE_ENTITY, 'Order not found');
        }
        //endregion

        $callback_data_payment = (array) $callback_data['payment'];
        $confirm_payment_result = $this->confirm_payment($order, $callback_data_payment, $callback_data, $callback_body);

        if (is_wp_error($confirm_payment_result)) {
            return self::return_response($confirm_payment_result->get_error_code(), $confirm_payment_result->get_error_message());
        }

        return self::return_response(\WP_Http::OK);
    }

    public function check_payment(\WC_Order $order)
    {
        if (!$this->check_settings()) {
            $message = $this->get_settings_admin_message();
            \WC_Admin_Meta_Boxes::add_error($message);
            return;
        }

        $order_id = $order->get_id();
        $qr_extension_status = null;

        $qr_extension_id = strval($order->get_meta(self::MOD_QR_EXTENSION_ID, true));
        if (empty($qr_extension_id)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-victoriabank-mia'), $order_id, self::MOD_QR_EXTENSION_ID));
            \WC_Admin_Meta_Boxes::add_error($message);
            return;
        }

        try {
            $client = $this->init_victoriabank_mia_client();
            $auth_token = $this->victoriabank_mia_generate_token($client);

            $qr_extension_status = $client->getQrExtensionStatus($qr_extension_id, $auth_token);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'response' => self::get_guzzle_error_response_body($ex),
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!empty($qr_extension_status)) {
            $qr_extension_status = $qr_extension_status->toArray();
            $qr_extension_status_value = strval($qr_extension_status['status']);

            /* translators: 1: Order ID, 2: Payment method title, 3: Payment status */
            $message = esc_html(sprintf(__('Order #%1$s %2$s payment status: %3$s', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->get_method_title(), $qr_extension_status_value));
            $message = $this->get_test_message($message);
            \WC_Admin_Meta_Boxes::add_error($message);

            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'qr_extension_status' => $qr_extension_status,
                )
            );

            if (strtolower($qr_extension_status_value) === 'paid') {
                $qr_extension_status_payments = (array) $qr_extension_status['payments'];

                if (!empty($qr_extension_status_payments)) {
                    $payment_data = (array) $qr_extension_status_payments[0];
                    $confirm_payment_result = $this->confirm_payment($order, $payment_data, $qr_extension_status);

                    if (is_wp_error($confirm_payment_result)) {
                        \WC_Admin_Meta_Boxes::add_error($confirm_payment_result->get_error_message());
                    }
                }
            }
        } else {
            /* translators: 1: Order ID */
            $message = esc_html(sprintf(__('Order #%1$s payment check failed.', 'payment-gateway-wc-victoriabank-mia'), $order_id));
            \WC_Admin_Meta_Boxes::add_error($message);
        }
    }

    protected function confirm_payment(\WC_Order $order, array $payment_data, array $payment_receipt_data, ?string $callback_body = null)
    {
        //region Check order data
        $payment_data_amount = (array) $payment_data['amount'];
        $payment_data_amount_sum = floatval($payment_data_amount['sum']);
        $payment_data_amount_currency = strval($payment_data_amount['currency']);

        $order_id = $order->get_id();
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();

        $order_price = $this->format_price($order_total, $order_currency);
        $payment_data_price = $this->format_price($payment_data_amount_sum, $payment_data_amount_currency);

        if ($order_price !== $payment_data_price) {
            /* translators: 1: Payment data price, 2: Order total price */
            $message = sprintf(__('Order payment data mismatch: Payment: %1$s, Order: %2$s.', 'payment-gateway-wc-victoriabank-mia'), $payment_data_price, $order_price);
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::ERROR);

            return new \WP_Error(\WP_Http::UNPROCESSABLE_ENTITY, 'Order payment data mismatch');
        }

        if ($order->is_paid()) {
            /* translators: 1: Order ID */
            $message = sprintf(__('Order #%1$s already fully paid.', 'payment-gateway-wc-victoriabank-mia'), $order_id);
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::WARNING);

            return new \WP_Error(\WP_Http::ACCEPTED, 'Order already fully paid');
        }
        //endregion

        //region Complete order payment
        $payment_data_reference = strval($payment_data['reference']);
        $payment_data_transaction_id = VictoriabankMiaClient::getPaymentTransactionId($payment_data_reference);

        if (!empty($callback_body)) {
            $order->update_meta_data(self::MOD_PAYMENT_CALLBACK, $callback_body);
        }

        $order->update_meta_data(self::MOD_PAYMENT_RECEIPT, wp_json_encode($payment_receipt_data));
        $order->update_meta_data(self::MOD_PAYMENT_REFERENCE, $payment_data_reference);
        $order->save();

        $order->payment_complete($payment_data_transaction_id);
        //endregion

        /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
        $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->get_method_title(), $payment_data_transaction_id));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::INFO,
            array(
                'payment_receipt_data' => $payment_receipt_data,
            )
        );

        $order->add_order_note($message);
        return true;
    }

    /**
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$this->check_settings()) {
            $message = wp_strip_all_tags($this->get_settings_admin_message());
            return new \WP_Error('check_settings', $message);
        }

        $order = wc_get_order($order_id);
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();
        $amount = isset($amount) ? floatval($amount) : $order_total;

        //region Validate refund amount
        if ($amount !== $order_total) {
            /* translators: 1: Payment method title */
            $message = esc_html(sprintf(__('Partial refunds are not currently supported by %1$s.', 'payment-gateway-wc-victoriabank-mia'), $this->get_method_title()));
            $this->log($message, \WC_Log_Levels::ERROR);

            return new \WP_Error('partial_refund', $message);
        }
        //endregion

        $payment_reference = strval($order->get_meta(self::MOD_PAYMENT_REFERENCE, true));
        if (empty($payment_reference)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-victoriabank-mia'), $order_id, self::MOD_PAYMENT_REFERENCE));
            return new \WP_Error('order_payment_reference', $message);
        }

        try {
            $client = $this->init_victoriabank_mia_client();
            $auth_token = $this->victoriabank_mia_generate_token($client);

            $transaction_id = VictoriabankMiaClient::getPaymentTransactionId($payment_reference);
            $client->reverseTransaction($transaction_id, $auth_token);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'response' => self::get_guzzle_error_response_body($ex),
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title, 4: Error message */
            $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed.', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::ERROR);

            $order->add_order_note($message);
            return new \WP_Error('process_refund', $message);
        }

        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'payment-gateway-wc-victoriabank-mia'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log($message, \WC_Log_Levels::INFO);
        $order->add_order_note($message);

        return true;
    }
    //endregion

    //region Integration
    public static function order_actions(array $actions, \WC_Order $order)
    {
        if (!$order->needs_payment() || $order->get_payment_method() !== self::MOD_ID) {
            return $actions;
        }

        /* translators: 1: Payment method title */
        $actions[self::MOD_ACTION_CHECK_PAYMENT] = esc_html(sprintf(__('Check %1$s order payment', 'payment-gateway-wc-victoriabank-mia'), self::MOD_TITLE));
        return $actions;
    }

    public static function action_check_payment(\WC_Order $order)
    {
        /** @var WC_Gateway_Victoriabank_MIA $plugin */
        $plugin = self::get_payment_gateway_instance();
        $plugin->check_payment($order);
    }
    //endregion
}
