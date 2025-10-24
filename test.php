<?php

require_once __DIR__ . '/vendor/autoload.php';

use Victoriabank\VictoriabankMia\VictoriabankMiaClient;


class VB_MIA_Test
{
    #region Properties
    /**
     * @var bool
     */
    private $DEBUG;

    /**
     * @var string
     */
    private $VB_MIA_BASE_URI;

    /**
     * @var string
     */
    private $VB_MIA_USERNAME;

    /**
     * @var string
     */
    private $VB_MIA_PASSWORD;

    /**
     * @var string
     */
    private $VB_PUBLIC_KEY_PATH;

    /**
     * @var string
     */
    private $VB_COMPANY_IBAN;

    /**
     * @var string
     */
    private $VB_COMPANY_NAME;
    #endregion

    public function __construct()
    {
        $this->DEBUG = getenv('DEBUG');
        $this->VB_MIA_BASE_URI = getenv('VB_MIA_BASE_URI');
        $this->VB_MIA_USERNAME = getenv('VB_MIA_USERNAME');
        $this->VB_MIA_PASSWORD = getenv('VB_MIA_PASSWORD');
        $this->VB_PUBLIC_KEY_PATH = getenv('VB_PUBLIC_KEY_PATH');
        $this->VB_COMPANY_NAME = getenv('VB_COMPANY_NAME');
        $this->VB_COMPANY_IBAN = getenv('VB_COMPANY_IBAN');
    }

    private function vb_mia_init_client()
    {
        $options = [
            'base_uri' => $this->VB_MIA_BASE_URI,
            'timeout' => 15
        ];

        if ($this->DEBUG) {
            $logName = 'victoriabank_mia_guzzle';
            $logFileName = "$logName.log";

            $log = new \Monolog\Logger($logName);
            $log->pushHandler(new \Monolog\Handler\StreamHandler($logFileName, \Monolog\Logger::DEBUG));

            $stack = \GuzzleHttp\HandlerStack::create();
            $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

            $options['handler'] = $stack;
        }

        $guzzleClient = new \GuzzleHttp\Client($options);
        $client = new VictoriabankMiaClient($guzzleClient);

        return $client;
    }

    /**
     * @param VictoriabankMiaClient $client
     */
    private function vb_mia_generate_token($client)
    {
        $tokenResponse = $client->getToken('password', $this->VB_MIA_USERNAME, $this->VB_MIA_PASSWORD);
        $accessToken = $tokenResponse['accessToken'];

        return $accessToken;
    }

    /**
     * @param VictoriabankMiaClient $client
     * @param string $authToken
     * @param string $order_id
     * @param string $order_name
     * @param float  $total_amount
     * @param string $currency
     * @param int    $validity_minutes
     */
    private function vb_mia_pay($client, $authToken, $order_id, $order_name, $total_amount, $currency, $validity_minutes)
    {
        $qr_data = array(
            'header' => array(
                'qrType' => 'DYNM', # Type of QR code: DYNM - Dynamic QR, STAT - Static QR, HYBR - Hybrid QR
                'amountType' => 'Fixed', # Specifies the type of amount: Fixed - Dynamic QR, Controlled - Static QR, Free - Hybrid QR
                'pmtContext' => 'e' #Payment context: m - mobile payment, e - e-commerce payment, i - invoice payment, 0 - other
            ),
            'extension' => array(
                'creditorAccount' => array(
                    'iban' => $this->VB_COMPANY_IBAN
                ),
                'amount' => array(
                    'sum' => $total_amount,
                    'currency' => $currency
                ),
                'dba' => $this->VB_COMPANY_NAME,
                'remittanceInfo4Payer' => $order_name,
                'creditorRef' => $order_id,
                'ttl' => array(
                    'length' => $validity_minutes, #The duration for which the QR code is valid.
                    'units' => 'mm' #The unit of time for the TTL: ss - seconds, mm - minutes
                )
            )
        );

        $create_qr_response = $client->createPayeeQr($qr_data, $authToken);
        return $create_qr_response;
    }

    public function test()
    {
        $client = $this->vb_mia_init_client();
        $authToken = $this->vb_mia_generate_token($client);
        //print($authToken);

        $order_id = '12345';
        $order_name = "Order #$order_id";
        $total_amount = 123.45;
        $currency = 'MDL';
        $vb_mia_pay_response = $this->vb_mia_pay($client, $authToken, $order_id, $order_name, $total_amount, $currency, 10);
        print_r($vb_mia_pay_response);

        $qr_extension_id = $vb_mia_pay_response['qrExtensionUUID'];
        $qr_url = $vb_mia_pay_response['qrAsText'];
        //$qr_image = $vb_mia_pay_response['qrAsImage'];
    }

    public function test_callback()
    {
        $victoriabank_mia_certificate = file_get_contents($this->VB_PUBLIC_KEY_PATH);
        $callback_body = '';
        $callback_data = VictoriabankMiaClient::decodeValidateCallback($callback_body, $victoriabank_mia_certificate);

        print_r($callback_data);
    }
}

putenv('DEBUG=1');

$vb_mia_test = new VB_MIA_Test();
$vb_mia_test->test();
#$vb_mia_test->test_callback();
