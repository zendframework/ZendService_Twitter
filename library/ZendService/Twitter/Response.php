<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Service
 */

namespace ZendService\Twitter;

use Zend\Http\Response as HttpResponse;
use Zend\Json\Exception\ExceptionInterface as JsonException;
use Zend\Json\Json;

class Response
{
    protected $httpResponse;

    protected $jsonBody;

    protected $rawBody;

    public function __construct(HttpResponse $httpResponse)
    {
        $this->httpResponse = $httpResponse;
        $this->rawBody      = $httpResponse->getBody();
        try {
            $jsonBody = Json::decode($this->rawBody, Json::TYPE_OBJECT);
            $this->jsonBody = $jsonBody;
        } catch (JsonException $e) {
            throw new Exception\DomainException(sprintf(
                'Unable to decode response from twitter: %s',
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function __get($name)
    {
        if (null === $this->jsonBody) {
            return null;
        }
        if (!isset($this->jsonBody->{$name})) {
            return null;
        }
        return $this->jsonBody->{$name};
    }

    public function isSuccess()
    {
        return $this->httpResponse->isSuccess();
    }

    public function isError()
    {
        return !$this->httpResponse->isSuccess();
    }

    public function getErrors()
    {
        if (!$this->isError()) {
            return array();
        }
        if (null === $this->jsonBody
            || !isset($this->jsonBody->errors)
        ) {
            throw new Exception\DomainException(
                'Either no JSON response received, or JSON error response is malformed; cannot return errors'
            );
        }
        return $this->jsonBody->errors;
    }

    public function getRawResponse()
    {
        return $this->rawBody;
    }

    public function toValue()
    {
        return $this->jsonBody;
    }
}
