<?php
use ZendService\Twitter\Twitter;
require_once __DIR__ . '/../../../../vendor/autoload.php';

$twitter = new Twitter(array(
    'access_token' => array(
        'token'  => '9453382-o1jag9yrT0ju8zYsIjKfLN2LMauVCqsph7JSGp0E4',
        'secret' => 'mICPQTLPcpcvvTWkc2DjHd3SkWUR6Bq4BD9yJUe4Xs',
    ),
    'oauth_options' => array(
        'consumerKey' => 'k7lHQfa4D2De6If5orOIfw',
        'consumerSecret' => 'ax6VgjiYA5D75bLudAiC3gQAp63u9O2fV5PnXSd0Dq4',
    ),
    'http_client_options' => array(
        'adapter' => 'Zend\Http\Client\Adapter\Curl',
    )
));

$response = $twitter->account->verifyCredentials();
if (!$response->isSuccess()) {
    echo "Could not verify credentials!\n";
    var_export($response->getErrors());
    exit(2);
}

$response = $twitter->users->search('Zend');
if (!$response->isSuccess()) {
    echo "Search failed!\n";
    var_export($response->getErrors());
    exit(2);
}
echo $response->getRawResponse();
