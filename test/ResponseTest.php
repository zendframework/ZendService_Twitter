<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendServiceTest\Twitter;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Zend\Http\Header\HeaderInterface;
use Zend\Http\Headers;
use Zend\Http\Response as HttpResponse;
use ZendService\Twitter\RateLimit;
use ZendService\Twitter\Response;

class ResponseTest extends TestCase
{
    public function testPopulateAddsRateLimitBasedOnHttpResponseHeaders()
    {
        $phpunit = $this;

        $headers = $this->prophesize(Headers::class);
        $headers->has('x-rate-limit-limit')->willReturn(true);
        $headers->get('x-rate-limit-limit')->will(function () use ($phpunit) {
            $header = $phpunit->prophesize(HeaderInterface::class);
            $header->getFieldValue()->willReturn(3600);
            return $header->reveal();
        });
        $headers->has('x-rate-limit-remaining')->willReturn(true);
        $headers->get('x-rate-limit-remaining')->will(function () use ($phpunit) {
            $header = $phpunit->prophesize(HeaderInterface::class);
            $header->getFieldValue()->willReturn(237);
            return $header->reveal();
        });
        $headers->has('x-rate-limit-reset')->willReturn(true);
        $headers->get('x-rate-limit-reset')->will(function () use ($phpunit) {
            $header = $phpunit->prophesize(HeaderInterface::class);
            $header->getFieldValue()->willReturn(4200);
            return $header->reveal();
        });

        $httpResponse = $this->prophesize(HttpResponse::class);
        $httpResponse->getHeaders()->will([$headers, 'reveal']);
        $httpResponse->getBody()->willReturn('{}');

        $response = new Response($httpResponse->reveal());
        $this->assertAttributeInstanceOf(RateLimit::class, 'rateLimit', $response);

        $r = new ReflectionProperty($response, 'rateLimit');
        $r->setAccessible(true);
        $rateLimit = $r->getValue($response);

        $this->assertSame(3600, $rateLimit->limit);
        $this->assertSame(237, $rateLimit->remaining);
        $this->assertSame(4200, $rateLimit->reset);
    }
}
