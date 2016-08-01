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

use Zend\Http\Headers as Headers;

/**
 * Representation of the Rate Limit Headers from Twitter.
 *
 */
class RateLimit
{
    private $limit;
    private $remaining;
    private $reset;
	
    /**
     * Constructor
     *
     * @param  null|Headers $headers
     */
	public function __construct(Headers $headers = null)
	{

        if (! is_null($headers)) {
	        $headersArray = $headers->toArray();
	        $this->limit = isset($headersArray['x-rate-limit-limit']) ? $headersArray['x-rate-limit-limit'] : 0;
	        $this->remaining = isset($headersArray['x-rate-limit-remaining']) ? $headersArray['x-rate-limit-remaining'] : 0;
	        $this->reset = isset($headersArray['x-rate-limit-reset']) ? $headersArray['x-rate-limit-reset'] : 0;
        }

	}


    /**
     * Retun the requested property
     *
     * @return null|integer
     */
	public function __get($key)
	{
		if (isset($this->$key)) {
			return $this->$key;
		}

		return null;
	}
}