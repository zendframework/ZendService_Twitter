<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendService\Twitter;

use Zend\Http\Client as Client;

/**
 * Twitter Media Uploader base class
 *
 * @author Cal Evans <cal@calevans.com>
 */
class Media
{
    const UPLOAD_BASE_URI = 'https://upload.twitter.com/1.1/media/upload.json';

    /**
     * @var int The maximum number of bytes to send to Twitter per request.
     */
    private $chunkSize = (1024 * 1024) * 4;

    /**
     * @var string Error message from f*() operations.
     */
    private $fileOperationError;

    /**
     * @var bool Whether or not the media upload is for a direct message.
     */
    private $forDirectMessage;

    /**
     * @var string Filename of image to upload.
     */
    private $imageFilename = '';

    /**
     * @var string Media category to use when media is for a direct message.
     */
    private $mediaCategory;

    /**
     * @var string|int Media identifier provided by Twitter following upload.
     */
    private $mediaId = 0;

    /**
     * @var string Mediatype of image.
     */
    private $mediaType = '';

    /**
     * @var int Next chunked upload offset.
     */
    private $segmentIndex = 0;

    /**
     * @var bool Whether or not the media will be shared across multiple
     *     direct messages.
     */
    private $shared;

    public function __construct(
        string $imageFilename,
        string $mediaType,
        bool $forDirectMessage = false,
        bool $shared = false
    ) {
        $this->imageFilename = $imageFilename;
        $this->mediaType = $mediaType;
        $this->forDirectMessage = $forDirectMessage;
        $this->shared = $shared;
    }

    /**
     * Main call into the upload workflow
     *
     * @throws Exception\InvalidMediaException If the file can't be opened.
     * @throws Exception\InvalidMediaException If no media type is present.
     */
    public function upload(Client $httpClient) : Response
    {
        $this->mediaId = 0;
        $this->segmentIndex = 0;

        if (! $this->validateFile($this->imageFilename)) {
            throw new Exception\InvalidMediaException('Failed to open ' . $this->imageFilename);
        }

        if (! $this->validateMediaType($this->mediaType)) {
            throw new Exception\InvalidMediaException('Invalid Media Type given.');
        }

        $httpClient->setUri(self::UPLOAD_BASE_URI);

        $totalBytes = filesize($this->imageFilename);
        $response = $this->initUpload($httpClient, $this->mediaType, $totalBytes);

        $this->mediaId = $response->toValue()->media_id;

        $this->appendUpload($httpClient);

        return $this->finalizeUpload($httpClient);
    }

    /**
     * Initalize the upload with Twitter.
     *
     * @throws Exception\MediaUploadException If upload initialization fails.
     */
    private function initUpload(Client $httpClient, string $mediaType, int $totalBytes) : Response
    {
        $payload = [
            'command'        => 'INIT',
            'media_category' => $this->deriveMediaCategeory($mediaType, $this->forDirectMessage),
            'media_type'     => $mediaType,
            'total_bytes'    => $totalBytes,
        ];

        if ($this->forDirectMessage && $this->shared) {
            $payload['shared'] = true;
        }

        $httpClient->resetParameters();
        $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
        $httpClient->setMethod('POST');
        $httpClient->setParameterPost($payload);
        $response = $httpClient->send();

        if (! $response->isSuccess()) {
            throw new Exception\MediaUploadException(sprintf(
                'Failed to initialize Twitter media upload: %s',
                $response->getBody()
            ));
        }

        return new Response($response);
    }

    /**
     * Send chunks of the file to Twitter.
     *
     * @throws Exception\MediaUploadException If any upload chunk operation fails.
     */
    private function appendUpload(Client $httpClient) : void
    {
        $payload = [
            'command'  => 'APPEND',
            'media_id' => $this->mediaId,
        ];

        set_error_handler($this->createErrorHandler(), E_WARNING);
        $fileHandle = fopen($this->imageFilename, 'rb');
        restore_error_handler();

        if (! $fileHandle) {
            throw new Exception\MediaUploadException(sprintf(
                'Failed to open the file in the APPEND method: %s',
                $this->fileOperationError
            ));
        }

        while (! feof($fileHandle)) {
            $data = fread($fileHandle, $this->chunkSize);

            $payload['media_data'] = base64_encode($data);
            $payload['segment_index'] = $this->segmentIndex++;

            $httpClient->resetParameters();
            $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
            $httpClient->setMethod('POST');
            $httpClient->setParameterPost($payload);

            $response = $httpClient->send();

            if (! $response->isSuccess()) {
                throw new Exception\MediaUploadException(
                    'Failed uploading segment '
                    . ($this->segmentIndex - 1)
                    . ' of '
                    . $this->imageFilename
                    . '. Error Code: ' . $response->getStatusCode()
                    . '. Reason: ' . $response->getReasonPhrase()
                );
            }
        }

        fclose($fileHandle);
    }

    /**
     * Tell Twitter to finalize the upload.
     */
    private function finalizeUpload(Client $httpClient) : Response
    {
        $payload = [
            'command'  => 'FINALIZE',
            'media_id' => $this->mediaId,
        ];

        $httpClient->resetParameters();
        $httpClient->setHeaders(['Content-type' => 'application/x-www-form-urlencoded']);
        $httpClient->setMethod('POST');
        $httpClient->setParameterPost($payload);
        return new Response($httpClient->send());
    }

    /**
     * Validate that the file exists and can be opened.
     *
     * @todo Put a check to make sure the file is local.
     */
    private function validateFile(string $fileName) : bool
    {
        $returnValue = false;

        set_error_handler($this->createErrorHandler(), E_WARNING);
        $returnValue = is_readable($fileName);
        restore_error_handler();

        return (bool) $returnValue;
    }

    /**
     * Validate the mediatype.
     */
    private function validateMediaType(string $mediaType) : bool
    {
        return 1 === preg_match('#^\w+/[-.\w]+(?:\+[-.\w]+)?#', $mediaType);
    }

    /**
     * Creates and returns an error handler.
     *
     * The error handler will store the error message string in the
     * $fileOperationError property.
     */
    private function createErrorHandler() : callable
    {
        $this->fileOperationError = null;
        return function ($errno, $errstr) {
            $this->fileOperationError = $errstr;
            return true;
        };
    }

    private function deriveMediaCategeory(string $mediaType, bool $forDirectMessage) : string
    {
        switch (true) {
            case ('image/gif' === strtolower($mediaType)):
                $category = 'gif';
                break;
            case (preg_match('#^video/#i', $mediaType)):
                $category = 'video';
                break;
            case (preg_match('#^image/#i', $mediaType)):
                // fall-through
            default:
                $category = 'image';
                break;
        }
        $prefix = $forDirectMessage ? 'dm_' : 'tweet_';
        return $prefix . $category;
    }
}
