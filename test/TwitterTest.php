<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Twitter;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use ReflectionProperty;
use stdClass;
use Zend\Http;
use ZendOAuth\Client as OAuthClient;
use ZendOAuth\Consumer as OAuthConsumer;
use ZendOAuth\Token\Access as AccessToken;
use ZendOAuth\Token\Request as RequestToken;
use ZendService\Twitter;
use ZendService\Twitter\Response as TwitterResponse;
use ZendService\Twitter\RateLimit as RateLimit;

use Zend\Http\Client\Adapter\Curl as CurlAdapter;

class TwitterTest extends TestCase
{
    public function setUp()
    {
        $twitter = new Twitter\Twitter();
        $r = new ReflectionProperty($twitter, 'jsonFlags');
        $r->setAccessible(true);
        $this->jsonFlags = $r->getValue($twitter);
    }

    /**
     * Quick reusable OAuth client stub setup. Its purpose is to fake
     * HTTP interactions with Twitter so the component can focus on what matters:
     * 1. Makes correct requests (URI, parameters and HTTP method)
     * 2. Parses all responses and returns a TwitterResponse
     * 3. TODO: Correctly utilises all optional parameters
     *
     * If used correctly, tests will be fast, efficient, and focused on
     * Zend_Service_Twitter's behaviour only. No other dependencies need be
     * tested. The Twitter API Changelog should be regularly reviewed to
     * ensure the component is synchronised to the API.
     *
     * @param string $path Path appended to Twitter API endpoint
     * @param string $method Do we expect HTTP GET or POST?
     * @param string $responseFile File containing a valid XML response to the request
     * @param array $params Expected GET/POST parameters for the request
     * @param array $responseHeaders Headers expected on the returned response
     * @return OAuthClient
     */
    protected function stubOAuthClient(
        $path,
        $method,
        $responseFile = null,
        array $params = null,
        array $responseHeaders = []
    ) {
        $client = $this->prophesize(OAuthClient::class);
        $client->setMethod($method)->will([$client, 'reveal']);
        $client->resetParameters()->will([$client, 'reveal']);
        $client->setUri('https://api.twitter.com/1.1/' . $path)->shouldBeCalled();
        $client->setHeaders(['Accept-Charset' => 'ISO-8859-1,utf-8'])->will([$client, 'reveal']);
        $client->clearCookies()->will([$client, 'reveal']);
        $client->getCookies()->willReturn([]);
        if (null !== $params && $method === 'GET') {
            $client->setParameterGet($params)->shouldBeCalled();
        }
        if (null !== $params && $method === 'POST') {
            $requestBody = json_encode($params, $this->jsonFlags);
            $client->setRawBody($requestBody)->shouldBeCalled();

            $headers = $this->prophesize(Http\Headers::class);
            $headers->addHeaderLine('Content-Type', 'application/json')->shouldBeCalled();
            $request = $this->prophesize(Http\Request::class);
            $request->getHeaders()->will([$headers, 'reveal']);
            $client->getRequest()->will([$request, 'reveal']);
        }

        $response = $this->prophesize(Http\Response::class);

        $response->getBody()->will(function () use ($responseFile) {
            if (null === $responseFile) {
                return '{}';
            }
            return file_get_contents(__DIR__ . '/_files/' . $responseFile);
        });

        $headers = $this->prophesize(Http\Headers::class);
        foreach ($responseHeaders as $headerName => $value) {
            $headers->has($headerName)->willReturn(true);
            $header = $this->prophesize(Http\Header\HeaderInterface::class);
            $header->getFieldValue()->willReturn($value);
            $headers->get($headerName)->will([$header, 'reveal']);
        }

        $response->getHeaders()->will([$headers, 'reveal']);

        $client->send()->will([$response, 'reveal']);

        return $client->reveal();
    }

    public function stubHttpClientInitialization()
    {
        $client = $this->prophesize(OAuthClient::class);
        $client->setHeaders(['Accept-Charset' => 'ISO-8859-1,utf-8'])->will([$client, 'reveal']);
        $client->resetParameters()->will([$client, 'reveal']);
        $client->clearCookies()->will([$client, 'reveal']);
        $client->getCookies()->willReturn([]);
        return $client->reveal();
    }

    public function testRateLimitHeaders()
    {
        $rateLimits = [
            'x-rate-limit-limit'     => rand(1, 100),
            'x-rate-limit-remaining' => rand(1, 100),
            'x-rate-limit-reset'     => rand(1, 100),
        ];

        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'users/show.json',
            Http\Request::METHOD_GET,
            'users.show.mwop.json',
            ['screen_name' => 'mwop'],
            $rateLimits
        ));

        $finalResponse = $twitter->users->show('mwop');

        $this->assertInstanceOf(TwitterResponse::class, $finalResponse);

        $rateLimit = $finalResponse->getRateLimit();
        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame($rateLimit->limit, $rateLimits['x-rate-limit-limit']);
        $this->assertSame($rateLimit->remaining, $rateLimits['x-rate-limit-remaining']);
        $this->assertSame($rateLimit->reset, $rateLimits['x-rate-limit-reset']);
    }


    /**
     * OAuth tests
     */

    public function testProvidingAccessTokenInOptionsSetsHttpClientFromAccessToken()
    {
        $client = $this->prophesize(OAuthClient::class);
        $token = $this->prophesize(AccessToken::class);
        $token->getHttpClient([
            'token' => $token->reveal(),
            'siteUrl' => 'https://api.twitter.com/oauth',
        ], 'https://api.twitter.com/oauth', [])->will([$client, 'reveal']);

        $twitter = new Twitter\Twitter(['accessToken' => $token->reveal(), 'opt1' => 'val1']);
        $this->assertSame($client->reveal(), $twitter->getHttpClient());
    }

    public function testNotAuthorisedWithoutToken()
    {
        $twitter = new Twitter\Twitter;
        $this->assertFalse($twitter->isAuthorised());
    }

    public function testChecksAuthenticatedStateBasedOnAvailabilityOfAccessTokenBasedClient()
    {
        $client = $this->prophesize(OAuthClient::class);
        $token = $this->prophesize(AccessToken::class);
        $token->getHttpClient([
            'token' => $token->reveal(),
            'siteUrl' => 'https://api.twitter.com/oauth',
        ], 'https://api.twitter.com/oauth', [])->will([$client, 'reveal']);

        $twitter = new Twitter\Twitter(['accessToken' => $token->reveal()]);
        $this->assertTrue($twitter->isAuthorised());
    }

    public function testRelaysMethodsToInternalOAuthInstance()
    {
        $oauth = $this->prophesize(OAuthConsumer::class);
        $oauth->getAccessToken([], Argument::type(RequestToken::class))->willReturn('foo');
        foreach ([
            'getRequestToken',
            'getRedirectUrl',
            'redirect',
            'getToken',
        ] as $method) {
            $oauth->$method()->willReturn('foo');
        }

        $twitter = new Twitter\Twitter(['opt1' => 'val1'], $oauth->reveal());
        $this->assertEquals('foo', $twitter->getRequestToken());
        $this->assertEquals('foo', $twitter->getRedirectUrl());
        $this->assertEquals('foo', $twitter->redirect());
        $this->assertEquals('foo', $twitter->getAccessToken(
            [],
            $this->prophesize(RequestToken::class)->reveal()
        ));
        $this->assertEquals('foo', $twitter->getToken());
    }

    public function testResetsHttpClientOnReceiptOfAccessTokenToOauthClient()
    {
        $requestToken = $this->prophesize(RequestToken::class)->reveal();
        $oauth = $this->prophesize(OAuthConsumer::class);
        $client = $this->prophesize(OAuthClient::class);
        $accessToken = $this->prophesize(AccessToken::class);

        $accessToken->getHttpClient([])->will([$client, 'reveal']);
        $oauth->getAccessToken([], $requestToken)->will([$accessToken, 'reveal']);
        $client->setHeaders(['Accept-Charset' => 'ISO-8859-1,utf-8'])->will([$client, 'reveal']);

        $twitter = new Twitter\Twitter([], $oauth->reveal());
        $twitter->getAccessToken([], $requestToken);
        $this->assertSame($client->reveal(), $twitter->getHttpClient());
    }

    public function testAuthorisationFailureWithUsernameAndNoAccessToken()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter(['username' => 'me']);
        $twitter->statusesPublicTimeline();
    }

    /**
     * @group ZF-8218
     */
    public function testUserNameNotRequired()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'users/show.json',
            Http\Request::METHOD_GET,
            'users.show.mwop.json',
            ['screen_name' => 'mwop']
        ));
        $response = $twitter->users->show('mwop');
        $this->assertInstanceOf('ZendService\Twitter\Response', $response);
        $exists = $response->id !== null;
        $this->assertTrue($exists);
    }

    /**
     * @group ZF-7781
     */
    public function testRetrievingStatusesWithValidScreenNameThrowsNoInvalidScreenNameException()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/user_timeline.json',
            Http\Request::METHOD_GET,
            'statuses.user_timeline.mwop.json',
            ['screen_name' => 'mwop']
        ));
        $twitter->statuses->userTimeline(['screen_name' => 'mwop']);
    }

    /**
     * @group ZF-7781
     */
    public function testRetrievingStatusesWithInvalidScreenNameCharacterThrowsInvalidScreenNameException()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter();
        $twitter->statuses->userTimeline(['screen_name' => 'abc.def']);
    }

    /**
     * @group ZF-7781
     */
    public function testRetrievingStatusesWithInvalidScreenNameLengthThrowsInvalidScreenNameException()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter();
        $twitter->statuses->userTimeline(['screen_name' => 'abcdef_abc123_abc123x']);
    }

    /**
     * @group ZF-7781
     */
    public function testStatusUserTimelineConstructsExpectedGetUriAndOmitsInvalidParams()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/user_timeline.json',
            Http\Request::METHOD_GET,
            'statuses.user_timeline.mwop.json',
            [
                'count' => '123',
                'user_id' => 783214,
                'since_id' => '10000',
                'max_id' => '20000',
                'screen_name' => 'twitter',
            ]
        ));
        $twitter->statuses->userTimeline([
            'id' => '783214',
            'since' => '+2 days', /* invalid param since Apr 2009 */
            'page' => '1',
            'count' => '123',
            'user_id' => '783214',
            'since_id' => '10000',
            'max_id' => '20000',
            'screen_name' => 'twitter'
        ]);
    }

    public function testOverloadingGetShouldReturnObjectInstanceWithValidMethodType()
    {
        $twitter = new Twitter\Twitter;
        $return = $twitter->statuses;
        $this->assertSame($twitter, $return);
    }

    public function testOverloadingGetShouldthrowExceptionWithInvalidMethodType()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter;
        $return = $twitter->foo;
    }

    public function testOverloadingGetShouldthrowExceptionWithInvalidFunction()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter;
        $return = $twitter->foo();
    }

    public function testMethodProxyingDoesNotThrowExceptionsWithValidMethods()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/sample.json',
            Http\Request::METHOD_GET,
            'statuses.sample.json',
            []
        ));
        $twitter->statuses->sample();
    }

    public function testMethodProxyingThrowExceptionsWithInvalidMethods()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter;
        $twitter->statuses->foo();
    }

    public function testVerifiedCredentials()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'account/verify_credentials.json',
            Http\Request::METHOD_GET,
            'account.verify_credentials.json',
            []
        ));
        $response = $twitter->account->verifyCredentials();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testSampleTimelineStatusReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/sample.json',
            Http\Request::METHOD_GET,
            'statuses.sample.json',
            []
        ));
        $response = $twitter->statuses->sample();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testRateLimitStatusReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'application/rate_limit_status.json',
            Http\Request::METHOD_GET,
            'application.rate_limit_status.json',
            []
        ));
        $response = $twitter->application->rateLimitStatus();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testRateLimitStatusHasHitsLeft()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'application/rate_limit_status.json',
            Http\Request::METHOD_GET,
            'application.rate_limit_status.json',
            []
        ));
        $response = $twitter->application->rateLimitStatus();
        $status = $response->toValue();
        $this->assertEquals(180, $status->resources->statuses->{'/statuses/user_timeline'}->remaining);
    }

    /**
     * TODO: Check actual purpose. New friend returns XML response, existing
     * friend returns a 403 code.
     */
    public function testFriendshipCreate()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'friendships/create.json',
            Http\Request::METHOD_POST,
            'friendships.create.twitter.json',
            ['screen_name' => 'twitter']
        ));
        $response = $twitter->friendships->create('twitter');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testHomeTimelineWithCountReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/home_timeline.json',
            Http\Request::METHOD_GET,
            'statuses.home_timeline.page.json',
            ['count' => 3]
        ));
        $response = $twitter->statuses->homeTimeline(['count' => 3]);
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     */
    public function testUserTimelineReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/user_timeline.json',
            Http\Request::METHOD_GET,
            'statuses.user_timeline.mwop.json',
            ['screen_name' => 'mwop']
        ));
        $response = $twitter->statuses->userTimeline(['screen_name' => 'mwop']);
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     */
    public function testPostStatusUpdateReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/update.json',
            Http\Request::METHOD_POST,
            'statuses.update.json',
            ['status' => 'Test Message 1']
        ));
        $response = $twitter->statuses->update('Test Message 1');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testPostStatusUpdateToLongShouldThrowException()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter;
        $twitter->statuses->update('Test Message - ' . str_repeat(' Hello ', 140));
    }

    public function testPostStatusUpdateEmptyShouldThrowException()
    {
        $this->expectException(Twitter\Exception\ExceptionInterface::class);
        $twitter = new Twitter\Twitter;
        $twitter->statuses->update('');
    }

    public function testShowStatusReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/show/307529814640840705.json',
            Http\Request::METHOD_GET,
            'statuses.show.json',
            []
        ));
        $response = $twitter->statuses->show('307529814640840705');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testCreateFavoriteStatusReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'favorites/create.json',
            Http\Request::METHOD_POST,
            'favorites.create.json',
            ['id' => 15042159587]
        ));
        $response = $twitter->favorites->create(15042159587);
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testFavoritesListReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'favorites/list.json',
            Http\Request::METHOD_GET,
            'favorites.list.json',
            []
        ));
        $response = $twitter->favorites->list();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testDestroyFavoriteReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'favorites/destroy.json',
            Http\Request::METHOD_POST,
            'favorites.destroy.json',
            ['id' => 15042159587]
        ));
        $response = $twitter->favorites->destroy(15042159587);
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testStatusDestroyReturnsResult()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/destroy/15042159587.json',
            Http\Request::METHOD_POST,
            'statuses.destroy.json'
        ));
        $response = $twitter->statuses->destroy(15042159587);
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testStatusHomeTimelineWithNoOptionsReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/home_timeline.json',
            Http\Request::METHOD_GET,
            'statuses.home_timeline.page.json',
            []
        ));
        $response = $twitter->statuses->homeTimeline();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testUserShowByIdReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'users/show.json',
            Http\Request::METHOD_GET,
            'users.show.mwop.json',
            ['screen_name' => 'mwop']
        ));
        $response = $twitter->users->show('mwop');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     * @todo rename to "mentions_timeline"
     */
    public function testStatusMentionsReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/mentions_timeline.json',
            Http\Request::METHOD_GET,
            'statuses.mentions_timeline.json',
            []
        ));
        $response = $twitter->statuses->mentionsTimeline();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     */
    public function testFriendshipDestroy()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'friendships/destroy.json',
            Http\Request::METHOD_POST,
            'friendships.destroy.twitter.json',
            ['screen_name' => 'twitter']
        ));
        $response = $twitter->friendships->destroy('twitter');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testBlockingCreate()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'blocks/create.json',
            Http\Request::METHOD_POST,
            'blocks.create.twitter.json',
            ['screen_name' => 'twitter']
        ));
        $response = $twitter->blocks->create('twitter');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testBlockingList()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'blocks/list.json',
            Http\Request::METHOD_GET,
            'blocks.list.json',
            ['cursor' => -1]
        ));
        $response = $twitter->blocks->list();
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testUsersShowAcceptsScreenNamesWithNumbers()
    {

        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient(
            $this->stubOAuthClient(
                'users/show.json',
                Http\Request::METHOD_GET,
                'users.show.JuicyBurger661.json',
                ['screen_name' => 'JuicyBurger661']
            )
        );
        // $id as screen_name with numbers
        $twitter->users->show('JuicyBurger661');
    }

    public function testUsersShowAcceptsIdAsStringArgument()
    {

        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient(
            $this->stubOAuthClient(
                'users/show.json',
                Http\Request::METHOD_GET,
                'users.show.mwop.json',
                ['user_id' => 9453382]
            )
        );
        // $id as string
        $twitter->users->show('9453382');
    }

    public function testUsersShowAcceptsIdAsIntegerArgument()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient(
            $this->stubOAuthClient(
                'users/show.json',
                Http\Request::METHOD_GET,
                'users.show.mwop.json',
                ['user_id' => 9453382]
            )
        );
        // $id as integer
        $twitter->users->show(9453382);
    }

    public function testBlockingIds()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'blocks/ids.json',
            Http\Request::METHOD_GET,
            'blocks.ids.json',
            ['cursor' => -1]
        ));
        $response = $twitter->blocks->ids();
        $this->assertInstanceOf(TwitterResponse::class, $response);
        $this->assertContains('23836616', $response->ids);
    }

    public function testBlockingDestroy()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'blocks/destroy.json',
            Http\Request::METHOD_POST,
            'blocks.destroy.twitter.json',
            ['screen_name' => 'twitter']
        ));
        $response = $twitter->blocks->destroy('twitter');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    /**
     * @group ZF-6284
     */
    public function testTwitterObjectsSoNotShareSameHttpClientToPreventConflictingAuthentication()
    {
        $twitter1 = new Twitter\Twitter(['username' => 'zftestuser1']);
        $twitter2 = new Twitter\Twitter(['username' => 'zftestuser2']);
        $this->assertNotSame($twitter1->getHttpClient(), $twitter2->getHttpClient());
    }

    public function testSearchTweets()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'search/tweets.json',
            Http\Request::METHOD_GET,
            'search.tweets.json',
            ['q' => '#zf2']
        ));
        $response = $twitter->search->tweets('#zf2');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testUsersSearch()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'users/search.json',
            Http\Request::METHOD_GET,
            'users.search.json',
            ['q' => 'Zend']
        ));
        $response = $twitter->users->search('Zend');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testListsSubscribers()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'lists/subscribers.json',
            Http\Request::METHOD_GET,
            'lists.subscribers.json',
            ['screen_name' => 'devzone']
        ));
        $response = $twitter->lists->subscribers('devzone');
        $this->assertInstanceOf(TwitterResponse::class, $response);
        $payload = $response->toValue();
        $this->assertCount(1, $payload->users);
        $this->assertEquals(4795561, $payload->users[0]->id);
    }

    public function testFriendsIds()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'users/search.json',
            Http\Request::METHOD_GET,
            'users.search.json',
            ['q' => 'Zend']
        ));
        $response = $twitter->users->search('Zend');
        $this->assertInstanceOf(TwitterResponse::class, $response);
        $payload = $response->toValue();
        $this->assertCount(20, $payload);
        $this->assertEquals(15012215, $payload[0]->id);
    }

    public function providerAdapterAlwaysReachableIfSpecifiedConfiguration()
    {
        $adapter = new CurlAdapter();

        return [
            [
                [
                    'http_client_options' => [
                        'adapter' => $adapter,
                    ],
                ],
                $adapter
            ],
            [
                [
                    'access_token' => [
                        'token'  => 'some_token',
                        'secret' => 'some_secret',
                    ],
                    'http_client_options' => [
                        'adapter' => $adapter,
                    ],
                ],
                $adapter
            ],
            [
                [
                    'access_token' => [
                        'token'  => 'some_token',
                        'secret' => 'some_secret',
                    ],
                    'oauth_options' => [
                        'consumerKey' => 'some_consumer_key',
                        'consumerSecret' => 'some_consumer_secret',
                    ],
                    'http_client_options' => [
                        'adapter' => $adapter,
                    ],
                ],
                $adapter
            ],
        ];
    }

    /**
     * @dataProvider providerAdapterAlwaysReachableIfSpecifiedConfiguration
      */
    public function testAdapterAlwaysReachableIfSpecified($config, $adapter)
    {
        $twitter = new Twitter\Twitter($config);
        $this->assertSame($adapter, $twitter->getHttpClient()->getAdapter());
    }

    public function testDirectMessagesNewRaisesExceptionForEmptyMessage()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubHttpClientInitialization());
        $this->expectException(Twitter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one char');
        $twitter->directMessagesNew('twitter', '');
    }

    public function testDirectMessagesNewRaisesExceptionForTooLongOfMessage()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubHttpClientInitialization());
        $text = str_pad('', 10001, 'X');
        $this->expectException(Twitter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no more than 10000 char');
        $twitter->directMessagesNew('twitter', $text);
    }

    public function testDirectMessageUsesEventsApi()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'direct_messages/events/new.json',
            Http\Request::METHOD_POST,
            'direct_messages.events.new.json',
            [
                'type' => 'message_create',
                'message_create' => [
                    'target' => [
                        'recipient_id' => '1',
                    ],
                    'message_data' => [
                        'text' => 'Message',
                    ],
                ],
            ]
        ));
        $response = $twitter->directMessages->new('1', 'Message');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testDirectMessageUsingScreenNameResultsInUserLookup()
    {
        $twitter = new Twitter\Twitter;
        $client = $this->prophesize(OAuthClient::class);
        $client->resetParameters()->will([$client, 'reveal']);
        $client->setHeaders(['Accept-Charset' => 'ISO-8859-1,utf-8'])->will([$client, 'reveal']);
        $client->clearCookies()->will([$client, 'reveal']);
        $client->getCookies()->willReturn([]);
        $client->setCookies([])->willReturn([]);

        $client->setUri('https://api.twitter.com/1.1/users/show.json')->shouldBeCalled();
        $client->setMethod('GET')->will([$client, 'reveal']);
        $client->setParameterGet(['screen_name' => 'Zend'])->shouldBeCalled();

        $userResponse = $this->prophesize(Http\Response::class);
        $userResponse->getBody()->willReturn('{"id_str":"1"}');
        $userResponse->getHeaders()->willReturn(null);
        $userResponse->isSuccess()->willReturn(true);

        $headers = $this->prophesize(Http\Headers::class);
        $headers->addHeaderLine('Content-Type', 'application/json')->shouldBeCalled();
        $request = $this->prophesize(Http\Request::class);
        $request->getHeaders()->will([$headers, 'reveal']);
        $client->getRequest()->will([$request, 'reveal']);

        $data = [
            'type' => 'message_create',
            'message_create' => [
                'target' => [
                    'recipient_id' => '1',
                ],
                'message_data' => [
                    'text' => 'Message',
                ],
            ],
        ];
        $client->setUri('https://api.twitter.com/1.1/direct_messages/events/new.json')->shouldBeCalled();
        $client->setMethod('POST')->will([$client, 'reveal']);
        $client->setRawBody(json_encode($data, $this->jsonFlags))->shouldBeCalled();

        $dmResponse = $this->prophesize(Http\Response::class);
        $dmResponse->getBody()->willReturn(file_get_contents(__DIR__ . '/_files/direct_messages.events.new.json'));
        $dmResponse->getHeaders()->willReturn(null);

        $client->send()->willReturn(
            $userResponse->reveal(),
            $dmResponse->reveal()
        );

        $twitter->setHttpClient($client->reveal());
        $response = $twitter->directMessages->new('Zend', 'Message');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testDirectMessageWithInvalidScreenNameResultsInException()
    {
        $twitter = new Twitter\Twitter;
        $client = $this->prophesize(OAuthClient::class);
        $client->resetParameters()->will([$client, 'reveal']);
        $client->setHeaders(['Accept-Charset' => 'ISO-8859-1,utf-8'])->will([$client, 'reveal']);
        $client->clearCookies()->will([$client, 'reveal']);
        $client->getCookies()->willReturn([]);
        $client->setCookies([])->willReturn([]);

        $client->setUri('https://api.twitter.com/1.1/users/show.json')->shouldBeCalled();
        $client->setMethod('GET')->will([$client, 'reveal']);
        $client->setParameterGet(['screen_name' => 'Zend'])->shouldBeCalled();

        $userResponse = $this->prophesize(Http\Response::class);
        $userResponse->getBody()->willReturn('{"id_str":"1"}');
        $userResponse->getHeaders()->willReturn(null);
        $userResponse->isSuccess()->willReturn(false);

        $client->getRequest()->shouldNotBeCalled();

        $client->setUri('https://api.twitter.com/1.1/direct_messages/events/new.json')->shouldNotBeCalled();
        $client->setMethod('POST')->shouldNotBeCalled();

        $client->send()->willReturn($userResponse->reveal());

        $twitter->setHttpClient($client->reveal());
        $this->expectException(Twitter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user');
        $response = $twitter->directMessages->new('Zend', 'Message');
    }

    public function testDirectMessageWithUserIdentifierSkipsUserLookup()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'direct_messages/events/new.json',
            Http\Request::METHOD_POST,
            'direct_messages.events.new.json',
            [
                'type' => 'message_create',
                'message_create' => [
                    'target' => [
                        'recipient_id' => '1',
                    ],
                    'message_data' => [
                        'text' => 'Message',
                    ],
                ],
            ]
        ));
        $response = $twitter->directMessages->new('1', 'Message');
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testDirectMessageAllowsProvidingMedia()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubOAuthClient(
            'direct_messages/events/new.json',
            Http\Request::METHOD_POST,
            'direct_messages.events.new.media.json',
            [
                'type' => 'message_create',
                'message_create' => [
                    'target' => [
                        'recipient_id' => '1',
                    ],
                    'message_data' => [
                        'text' => 'Message',
                        'attachment' => [
                            'type' => 'media',
                            'media' => [
                                'id' => 'XXXX',
                            ],
                        ],
                    ],
                ],
            ]
        ));
        $response = $twitter->directMessages->new('1', 'Message', ['media_id' => 'XXXX']);
        $this->assertInstanceOf(TwitterResponse::class, $response);
    }

    public function testStatusesShowWillPassAdditionalOptionsWhenPresent()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'statuses/show/12345.json',
            Http\Request::METHOD_GET,
            'statuses.show.json',
            [
                'tweet_mode' => 'extended',
                'include_entities' => true,
                'trim_user' => true,
                'include_my_retweet' => true,
            ]
        ));

        $finalResponse = $twitter->statuses->show(12345, [
            'tweet_mode' => true,
            'include_entities' => true,
            'trim_user' => true,
            'include_my_retweet' => true,
            'should_not_be_included' => true,
        ]);

        $this->assertInstanceOf(TwitterResponse::class, $finalResponse);
    }

    public function testListsMembersCanBeCalledWithListIdentifierOnly()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'lists/members.json',
            Http\Request::METHOD_GET,
            'lists.members.json',
            [
                'list_id' => 12345,
            ]
        ));

        $finalResponse = $twitter->lists->members(12345);
        $this->assertInstanceOf(TwitterResponse::class, $finalResponse);
    }

    public function testListsMembersCanBeCalledWithListSlugAndIntegerOwnerId()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'lists/members.json',
            Http\Request::METHOD_GET,
            'lists.members.json',
            [
                'slug'     => 'zendframework',
                'owner_id' => 12345,
            ]
        ));

        $finalResponse = $twitter->lists->members('zendframework', ['owner_id' => 12345]);
        $this->assertInstanceOf(TwitterResponse::class, $finalResponse);
    }

    public function testListsMembersCanBeCalledWithListSlugAndStringOwnerScreenName()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubOAuthClient(
            'lists/members.json',
            Http\Request::METHOD_GET,
            'lists.members.json',
            [
                'slug'              => 'zendframework',
                'owner_screen_name' => 'zfdevteam',
            ]
        ));

        $finalResponse = $twitter->lists->members('zendframework', ['owner_screen_name' => 'zfdevteam']);
        $this->assertInstanceOf(TwitterResponse::class, $finalResponse);
    }

    public function testListsMembersRaisesExceptionIfSlugPassedWithoutOwnerInformation()
    {
        $twitter = new Twitter\Twitter();
        $this->expectException(Twitter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing owner info');
        $twitter->lists->members('zendframework');
    }

    public function invalidIntegerIdentifiers() : array
    {
        return [
            'null'       => [null],
            'true'       => [true],
            'false'      => [false],
            'zero-float' => [0.0],
            'float'      => [1.1],
            'string'     => ['not-an-integer'],
            'array'      => [[1]],
            'object'     => [new stdClass()],
        ];
    }

    /**
     * @dataProvider invalidIntegerIdentifiers
     * @param mixed $ownerId
     */
    public function testListsMembersRaisesExceptionIfSlugPassedWithInvalidOwnerId($ownerId)
    {
        $twitter = new Twitter\Twitter();
        $this->expectException(Twitter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid owner_id');
        $twitter->lists->members('zendframework', ['owner_id' => $ownerId]);
    }

    public function invalidStringIdentifiers() : array
    {
        return [
            'empty'         => [''],
            'too-long'      => [implode('', range('a', 'z'))],
            'invalid-chars' => ['this-is !inv@lid'],
        ];
    }

    /**
     * @dataProvider invalidStringIdentifiers
     */
    public function testListsMembersRaisesExceptionIfSlugPassedWithInvalidOwnerScreenName(string $owner)
    {
        $twitter = new Twitter\Twitter();
        $this->expectException(Twitter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid owner_screen_name');
        $twitter->lists->members('zendframework', ['owner_screen_name' => $owner]);
    }
}
