<?php

namespace ZendService\Twitter;

use Zend\Http\Headers as Headers;

class RateLimit
{
    private $limit;
    private $remaining;
    private $reset;
	
	public function __construct(Headers $headers = null)
	{

        if (! is_null($headers)) {
	        $headersArray = $headers->toArray();
	        $this->limit = isset($headersArray['x-rate-limit-limit']) ? $headersArray['x-rate-limit-limit'] : 0;
	        $this->remaining = isset($headersArray['x-rate-limit-remaining']) ? $headersArray['x-rate-limit-remaining'] : 0;
	        $this->reset = isset($headersArray['x-rate-limit-reset']) ? $headersArray['x-rate-limit-reset'] : 0;
        }

	}


	public function __get($key)
	{
		if (isset($this->$key)) {
			return $this->$key;
		}

		return;
	}
}