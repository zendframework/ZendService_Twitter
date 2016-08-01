<?php

namespace ZendService\Twitter;

class RateLimit
{
    private $limit = 0;
    private $remaining = 0;
    private $reset = 0;
	
	public function __construct($headers)
	{
        $this->limit = isset($headers['x-rate-limit-limit']) ? $headers['x-rate-limit-limit'] : 0;
        $this->remaining = isset($headers['x-rate-limit-remaining']) ? $headers['x-rate-limit-remaining'] : 0;
        $this->reset = isset($headers['x-rate-limit-reset']) ? $headers['x-rate-limit-reset'] : 0;

	}

	public function __get($key)
	{
		if exists ($this>$key) {
			return $this->$key
		}

		return;
	}
}