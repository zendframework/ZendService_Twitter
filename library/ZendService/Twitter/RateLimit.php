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
            ? $headers->get('x-rate-limit-limit')->getFieldValue()
            : 0;
        $this->remaining = $headers->has('x-rate-limit-remaining')
            ? $headers->get('x-rate-limit-remaining')->getFieldValue()
            : 0;
        $this->reset = $headers->has('x-rate-limit-reset')
            ? $headers->get('x-rate-limit-reset')->getFieldValue()
            : 0;
    }


    /**
     * Retun the requested property
     *
     * @param string $key
     * @return null|int
     */
    public function __get($key)
    {
        return isset($this->$key) ? $this->$key : null;
    }

    /**
     * Is the requested property available?
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        return property_exists($this, $key);
    }
}
