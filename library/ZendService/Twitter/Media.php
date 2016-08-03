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
    protected $chunkSize = (1024*1024)*4;

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
     * @param  null|string $image_filename
     * @param  string $consumer
     */
    public function __construct($image_filename = null, $media_type = '')
    {
        $this->data['image_filename'] = '';
        $this->data['media_id']       = 0;
        $this->data['segment_index']  = 0;
        $this->data['media_type']     = $media_type;

        if (! is_null($image_filename)) {
            $this->data['image_filename']=$image_filename;
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
        if (! $this->validateFile($this->data['image_filename'])) {
            throw new \Exception('Failed to open '.$this->data['image_filename']);
        }

        if (empty($this->data['media_type'])) {
            throw new \Exception('No Media Type given.');
        }

        $params = [];
        $httpClient->setUri($this->baseUri . $this->endPoint);
        
        $params['total_bytes'] = filesize($this->data['image_filename']);
        $params['media_type']  = $this->data['media_type'];

        $response = $this->initUpload($httpClient,$params);
        $initResponse = $response->toValue();

        if (! $response->isSuccess()) {
            throw new \Exception('Failed to init the upload with Twitter.');
        }

        $this->data['media_id'] = $initResponse->media_id;

        $success = $this->appendUpload($httpClient,$params);

        if (! $success) {
            throw new \Exception('Failed the APPEND stage of the upload.');
        }

        $response = $this->finalizeUpload($httpClient,$params);

        return $response;
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
        $payload = ['command'     => 'INIT',
                    'media_type'  => $params['media_type'],
                    'total_bytes' => $params['total_bytes']];

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
        $payload = ['command'   => 'APPEND',
                    'media_id'] => $this->data['media_id']];

        $fileHandle = fopen($this->data['image_filename'],'rb');

        if (! $fileHandle)) {
            throw new \Exception('Failed to open the file in the APPEND method.');
        }
        
        while (! feof($fileHandle)) 
        {
            $data = fread($fileHandle, $this->chunkSize);

            $payload['media_data'] = base64_encode($data);
            $payload['segment_index'] = $this->data['segment_index']++;

            $httpClient->resetParameters();
            $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
            $httpClient->setMethod('POST');
            $httpClient->setParameterPost($payload);

            $response = $httpClient->send();
            $appendStatus = $response->isSuccess();

            if (! $appendStatus) {
                throw new \Exception('Failed uploading segment ' . 
                                     ($this->data['segment_index']-1) . ' of ' . 
                                     $this->data['image_filename'] . 
                                     ". Error Code: ". $response->getStatusCode() . 
                                     ". Reason: " . $response->getReasonPhrase());
            }

        }

        fClose($fileHandle);

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
        $payload = ['command'  => 'FINALIZE',
                    'media_id' => $this->data['media_id']];

        $httpClient->resetParameters();
        $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
        $httpClient->setMethod('POST');
        $httpClient->setParameterPost($payload);
        $response = $httpClient->send();
        
        return new Response($response);
    }
    
    
    /**
     * Validate that the file exists and can be opened.
     * 
     * @todo Put a check to make sure the file is local.
     * @param string $fileName
     * @return boolean
     */
    protected function validateFile($fileName)
    {
        $returnValue = false;
        $returnValue = file_exists($fileName);

        if ($returnValue) {
            $returnValue = ($handle = fopen($fileName,'rb'));

            if ($returnValue) {
                fClose($handle);
            }

        }

        return $returnValue;
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

}