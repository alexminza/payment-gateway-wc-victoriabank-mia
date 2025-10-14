<?php

require_once __DIR__ . '/vendor/autoload.php';

use Victoriabank\VictoriabankMia\VictoriabankMiaClient;

// define('DEBUG', getenv('DEBUG'));
// define('VB_MIA_BASE_URI', getenv('VB_MIA_BASE_URI'));
// define('VB_MIA_USERNAME', getenv('VB_MIA_USERNAME'));
// define('VB_MIA_PASSWORD', getenv('VB_MIA_PASSWORD'));
// define('VB_IBAN', getenv('VB_IBAN'));
// define('VB_PUBLIC_KEY_PATH', getenv('VB_PUBLIC_KEY_PATH'));


class VB_MIA_Test
{
    private $DEBUG;
    private $VB_MIA_BASE_URI;
    private $VB_MIA_USERNAME;
    private $VB_MIA_PASSWORD;
    private $VB_IBAN;
    private $VB_PUBLIC_KEY_PATH;

    public function __construct()
    {
        $this->DEBUG = getenv('DEBUG');
        $this->VB_MIA_BASE_URI = getenv('VB_MIA_BASE_URI');
        $this->VB_MIA_USERNAME = getenv('VB_MIA_USERNAME');
        $this->VB_MIA_PASSWORD = getenv('VB_MIA_PASSWORD');
        $this->VB_IBAN = getenv('VB_IBAN');
        $this->VB_PUBLIC_KEY_PATH = getenv('VB_PUBLIC_KEY_PATH');
    }

    private function vb_mia_init_client()
    {
        $options = [
            'base_uri' => $this->VB_MIA_BASE_URI,
            'timeout' => 15
        ];

        if ($this->DEBUG) {
            $log = new \Monolog\Logger('victoriabank_mia_guzzle_request');
            $logFileName = 'victoriabank_mia_guzzle.log';
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
        $token = $client->getToken('password', $this->VB_MIA_USERNAME, $this->VB_MIA_PASSWORD);
        return $token;
    }

    public function test()
    {
        $client = $this->vb_mia_init_client();
        $token = $this->vb_mia_generate_token($client);
        print($token);
    }
}

$vb_mia_test = new VB_MIA_Test();
$vb_mia_test->test();
