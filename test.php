<?php

require_once __DIR__ . '/vendor/autoload.php';

use Victoriabank\VictoriabankMia\VictoriabankMiaClient;

define('DEBUG', getenv('DEBUG'));
define('VB_MIA_BASE_URI', getenv('VB_MIA_BASE_URI'));
define('VB_MIA_USERNAME', getenv('VB_MIA_USERNAME'));
define('VB_MIA_PASSWORD', getenv('VB_MIA_PASSWORD'));
define('VB_IBAN', getenv('VB_IBAN'));
define('VB_PUBLIC_KEY_PATH', getenv('VB_PUBLIC_KEY_PATH'));


function init_vb_client()
{
    $options = [
        'base_uri' => VB_MIA_BASE_URI
    ];

    if (DEBUG) {
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


$victoriabank_mia_client = init_vb_client();
$token = $victoriabank_mia_client->getToken('password', VB_MIA_USERNAME, VB_MIA_PASSWORD);
print($token);