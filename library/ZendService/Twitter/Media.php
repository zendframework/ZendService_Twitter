<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Service
 */

namespace ZendService\Twitter;

use Zend\Http\Client as Client;
use ZendService\Twitter\Response as Response;


/**
 * Twitter Media Uploader base class
 *
 * @category   Zend
 * @package    Zend_Service
 * @subpackage Twitter
 * @author Cal Evans <cal@calevans.com>
 */
Class Media
{

    /**
     * Array of basic data to be stored by this class.
     *
     * @var Array
     */
	protected $data = [];

    /**
     * The number of bytes to send to Twitter at one time.
     * @var Integer
     */
	protected $chunkSize = 1048576;

    /**
     * The base URI for this API call
     *
     * @var String
     */
	protected $baseUri = 'https://upload.twitter.com';

    /**
     * The API endpoint for all calls.
     *
     * @var String
     */
	protected $endPoint = '/1.1/media/upload.json';


    /**
     * Constructor
     *
     * @param  null|string $image_url
     * @param  string $consumer
     */
	public function __construct($image_url = null, $media_type = '')
	{
		$this->data['image_url']='';
		$this->data['baseUri'] = 'https://upload.twitter.com';
		$this->data['end_point'] = '/1.1/media/upload.json';
		$this->data['media_id'] = 0;
		$this->data['segment_index'] = 0;
		$this->data['media_type'] = $media_type;


		if (! is_null($image_url)) {
			$this->data['image_url']=$image_url;
		}	
	}


    /**
     * Main call into the upload workflow
     *
     * @param  Client $httpClient
     * @return Twitter\Response
     * @throws \Exception If the file can't be opened.
     */
	public function upload(Client $httpClient)
	{
        $params = [];
        $params['file_handle'] = fopen($this->data['image_url'],'rb');

		if (is_null($params['file_handle'])) {
			throw new \Exception('Cannot open ' . $this->data['image_url']); 
		}

		$params['media_type'] = $this->data['media_type'];
		$params['total_bytes'] = $this->getFileSize($this->data['image_url']);

        $httpClient->setUri($this->data['baseUri'] . $this->data['end_point']);

		$holding = $this->initUpload($httpClient,$params);
		$initResponse = $holding->toValue();
		$this->data['media_id'] = $initResponse->media_id;

		$params['media_id'] = $this->data['media_id'];
		$success = $this->appendUpload($httpClient,$params);


		$response = $this->finalizeUpload($httpClient,$params);
		fClose($params['file_handle']);	

		return $response;
	}


    /**
     * Method overloading
     *
     * @param  string $key
     * @return mixed
     */
	public function __get($key)
	{
		return isset($this->data[$key])?$this->data[$key]:null;
	}


    /**
     * Method overloading
     *
     * @param string $key
     * @param mixed $value
	 */
	public function __set($key, $value)
	{
		if (isset($this->data[$key])) {
			$this->data[$key]= $value;
		}

		return;
	}


    /**
     * Initalize the upload with Twitter
     *
     * @param Http\Client $httpClient
     * @params array $params
     * @return Twitter\Response
     */
	protected function initUpload($httpClient, $params)
	{
		$payload = [];
		$payload['command'] = 'INIT';
		$payload['media_type'] = $params['media_type'];
		$payload['total_bytes'] = $params['total_bytes'];

        $httpClient->resetParameters();
        $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
		$httpClient->setMethod('POST');
		$httpClient->setParameterPost($payload);
        $response = $httpClient->send();
        return new Response($response);
	}


    /**
     * Send the chunks of the file to Twitter
     *
     * @param Http\Client $httpClient
     * @params array $params
     * @return boolean
     */
	protected function appendUpload($httpClient, $params)
	{
		$payload = [];
		$payload['command'] = 'APPEND';
		$payload['media_id'] = $params['media_id'];

		/* 
		 * Chunksize is set pretty high so this really should never trigger.
		 * But it's here in case someone reduced chunksize
		 */
		$appendStatus = true;

		while ($appendStatus and ! feof($params['file_handle'])) 
		{
			$payload['media_data'] = base64_encode(fread($params['file_handle'], $this->chunkSize));
			$payload['segment_index'] = $this->data['segment_index']++;
	        $httpClient->resetParameters();
	        $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
			$httpClient->setMethod('POST');
			$httpClient->setParameterPost($payload);
	        $response = $httpClient->send();
			$appendStatus = $response->isSuccess();
			if (! $appendStatus) {
				throw new \Exception('Failed uploading segment ' . ($this->data['segment_index']-1) . ' of ' . $this->data['image_url']);
			}

		}

		return $appendStatus;
	}


    /**
     * Send the upload finalize to Twitter
     *
     * @param Http\Client $httpClient
     * @params array $params
     * @return Twitter\Response
     */
	protected function finalizeUpload($httpClient,$params)
	{
		$payload = [];
		$payload['command'] = 'FINALIZE';
		$payload['media_id'] = $this->data['media_id'];

        $httpClient->resetParameters();
        $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
		$httpClient->setMethod('POST');
		$httpClient->setParameterPost($payload);
        $response = $httpClient->send();
		return new Response($response);

	}


    /**
     * Send send a file size request to the web server hosting the media
     *
     * @param string $file
     * @return integer
     */
	protected function getFileSize($file)
	{
		$contentLength = 0;
		
	    $ch = curl_init($file);
	    curl_setopt($ch, CURLOPT_NOBODY, true);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_HEADER, true);
	    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

	    $data = curl_exec($ch);
	    curl_close($ch);

	    if (preg_match('/Content-Length: (\d+)/', $data, $matches)) {

	        // Contains file size in bytes
	        $contentLength = (int)$matches[1];

	    }		

	    return $contentLength;
	}

}