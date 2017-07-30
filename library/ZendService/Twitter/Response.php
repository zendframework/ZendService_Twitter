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

use Zend\Http\Response as HttpResponse;
use Zend\Json\Exception\ExceptionInterface as JsonException;
use Zend\Json\Json;

/**
 * Representation of a response from Twitter.
 *
 * Provides:
 *
 * - method for testing if we have a successful call
 * - method for retrieving errors, if any
 * - method for retrieving the raw JSON
 * - method for retrieving the decoded response
 * - proxying to elements of the decoded response via property overloading
 */
class Response
{
    /**
     * @var HttpResponse
     */
    protected $httpResponse;

    /**
     * @var array|\stdClass
     */
    protected $jsonBody;

    /**
     * @var string
     */
    protected $rawBody;

    /**
     * @var RateLimit
     */
    protected $rateLimit;


    /**
     * Constructor
     *
     * Assigns the HttpResponse to a property, as well as the body
     * representation. It then attempts to decode the body as JSON.
     *
     * @param  HttpResponse $httpResponse
     * @throws Exception\DomainException if unable to decode JSON response
     */
    public function __construct(HttpResponse $httpResponse = null)
    {
        $this->httpResponse = $httpResponse;

        if (! is_null($httpResponse) and ! empty($httpResponse->getBody())) {
            $this->populate($httpResponse);
        }
    }

    /**
     * Property overloading to JSON elements
     *
     * If a named property exists within the JSON response returned,
     * proxies to it. Otherwise, returns null.
     *
     * @param  string $name
     * @return mixed
     */
    public function __get($name)
    {
        if (null === $this->jsonBody) {
            return null;
        }
        if (! isset($this->jsonBody->{$name})) {
            return null;
        }
        return $this->jsonBody->{$name};
    }

    /**
     * Was the request successful?
     *
     * @return bool
     */
    public function isSuccess()
    {
        return $this->httpResponse->isSuccess();
    }

    /**
     * Did an error occur in the request?
     *
     * @return bool
     */
    public function isError()
    {
        return ! $this->httpResponse->isSuccess();
    }

    /**
     * Retrieve the errors.
     *
     * Twitter _should_ return a standard error object, which contains an
     * "errors" property pointing to an array of errors. This method will
     * return that array if present, and raise an exception if not detected.
     *
     * If the response was successful, an empty array is returned.
     *
     * @return array
     * @throws Exception\DomainException if unable to detect structure of error response
     */
    public function getErrors()
    {
        if (! $this->isError()) {
            return [];
        }
        if (null === $this->jsonBody
            || ! isset($this->jsonBody->error)
        ) {
            throw new Exception\DomainException(
                'Either no JSON response received, or JSON error response is malformed; cannot return errors'
            );
        }
        return $this->jsonBody->error;
    }

    /**
     * Retrieve the raw response body
     *
     * @return string
     */
    public function getRawResponse()
    {
        return $this->rawBody;
    }

    /**
     * Retun the decoded response body
     *
     * @return array|\stdClass
     */
    public function toValue()
    {
        return $this->jsonBody;
    }

    /**
     * Retun the RateLimit object
     *
     * @return RateLimit
     */
    public function getRateLimit()
    {
        return $this->rateLimit;
    }

    /**
     * Populates the object with info. This can possibly called from the
     * constructor, or it can be called later.
     *
     */
    public function populate($httpResponse = null)
    {

        if (is_null($httpResponse)) {
            $httpResponse = $this->httpResponse;
        }

        $this->httpResponse = $httpResponse;
        $this->rawBody      = $httpResponse->getBody();

        $this->rateLimit = new RateLimit($this->httpResponse->getHeaders());

        try {
            $jsonBody = Json::decode($this->rawBody, Json::TYPE_OBJECT);
            $this->jsonBody = $jsonBody;
        } catch (JsonException $e) {
            throw new Exception\DomainException(sprintf(
                'Unable to decode response from twitter: %s',
                $e->getMessage()
            ), 0, $e);
        }

        return;
    }
}
