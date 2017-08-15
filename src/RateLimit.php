<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendService\Twitter;

use Zend\Http\Headers as Headers;

/**
 * Representation of the Rate Limit Headers from Twitter.
 */
class RateLimit
{
    /**
     * @var int
     */
    private $limit;

    /**
     * @var int
     */
    private $remaining;

    /**
     * @var int
     */
    private $reset;

    /**
     * Constructor
     *
     * @param  null|Headers $headers
     */
    public function __construct(Headers $headers = null)
    {
        if (! $headers) {
            return;
        }

        $this->limit = $headers->has('x-rate-limit-limit')
            ? (int) $headers->get('x-rate-limit-limit')->getFieldValue()
            : 0;
        $this->remaining = $headers->has('x-rate-limit-remaining')
            ? (int) $headers->get('x-rate-limit-remaining')->getFieldValue()
            : 0;
        $this->reset = $headers->has('x-rate-limit-reset')
            ? (int) $headers->get('x-rate-limit-reset')->getFieldValue()
            : 0;
    }


    /**
     * Retun the requested property
     *
     * @param string $key
     * @return null|int
     */
    public function __get($key) : ?int
    {
        return isset($this->$key) ? $this->$key : null;
    }

    /**
     * Is the requested property available?
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key) : bool
    {
        return property_exists($this, $key);
    }
}
