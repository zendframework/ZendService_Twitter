<?php
use Zend\Json\Json;
use Zend\Json\Decode;
require_once '/home/matthew/tmp/composer/vendor/autoload.php';
$json = file_get_contents('users.search.raw.json');
$php  = Json::decode($json);
$json = Json::encode($php /*, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK|JSON_BIGINT_AS_STRING|JSON_UNESCAPED_SLASHES */);
$json = Json::prettyPrint($json, array('indent' => '  '));
echo $json;
