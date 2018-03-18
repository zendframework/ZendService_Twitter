<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendService\Twitter;

use Closure;
use Normalizer;
use Traversable;
use ZendOAuth as OAuth;
use Zend\Http;
use Zend\Stdlib\ArrayUtils;
use Zend\Uri;

/**
 * Interact with the Twitter API.
 *
 * Note: most `$id` parameters accept either string or integer values. This is
 * due to the fact that identifiers in the Twitter API may exceed PHP_INT_MAX.
 *
 * Note on character counting: Twitter accepts UTF-8 encoded text via the API,
 * and counts multi-byte characters as a single character. PHP's strlen(),
 * however, treats each byte as a character for purposes of determing the
 * string length. To get around that, we can pass the message to utf8_decode,
 * which will replace any multi-byte characters with a `?`; this works fine
 * for counting lengths.
 *
 * @see https://developer.twitter.com/en/docs/basics/counting-characters
 */
class Twitter
{
    /**
     * Base URI for all API calls
     */
    const API_BASE_URI = 'https://api.twitter.com/1.1/';

    /**
     * OAuth Endpoint
     */
    const OAUTH_BASE_URI = 'https://api.twitter.com/oauth';

    /**
     * Paths that use JSON payloads (vs form-encoded)
     */
    const PATHS_JSON_PAYLOAD = [
        'direct_messages/events/new',
        'direct_messages/welcome_messages/new',
        'direct_messages/welcome_messages/rules/new',
    ];

    /**
     * As of November 2017, the character limit for status messages is 280.
     */
    const STATUS_MAX_CHARACTERS = 280;

    /**
     * @var array
     */
    private $cookieJar;

    /**
     * Date format for 'since' strings
     *
     * @var string
     */
    private $dateFormat = 'D, d M Y H:i:s T';

    /**
     * @var Http\Client|null
     */
    private $httpClient;

    /**
     * Flags to use with json_encode for POST requests
     *
     * @var int
     */
    private $jsonFlags = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Current method type (for method proxying)
     *
     * @var string|null
     */
    private $methodType;

    /**
     * Types of API methods
     *
     * @var array
     */
    private $methodTypes = [
        'account',
        'application',
        'blocks',
        'directmessages',
        'favorites',
        'followers',
        'friends',
        'friendships',
        'lists',
        'search',
        'statuses',
        'users',
    ];

    /**
     * Oauth Consumer
     *
     * @var OAuth\Consumer|null
     */
    private $oauthConsumer;

    /**
     * Options passed to constructor
     *
     * @var array
     */
    private $options = [];

    /**
     * Username
     *
     * @var string
     */
    private $username;

    public function __construct(
        iterable $options = null,
        OAuth\Consumer $consumer = null,
        Http\Client $httpClient = null
    ) {
        if ($options instanceof Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        }
        if (! is_array($options)) {
            $options = [];
        }
        $this->options = $options;

        if (isset($options['username'])) {
            $this->setUsername($options['username']);
        }

        $accessToken = false;
        if (isset($options['accessToken'])) {
            $accessToken = $options['accessToken'];
        } elseif (isset($options['access_token'])) {
            $accessToken = $options['access_token'];
        }

        $oauthOptions = [];
        if (isset($options['oauthOptions'])) {
            $oauthOptions = $options['oauthOptions'];
        } elseif (isset($options['oauth_options'])) {
            $oauthOptions = $options['oauth_options'];
        }
        $oauthOptions['siteUrl'] = static::OAUTH_BASE_URI;

        $httpClientOptions = [];
        if (isset($options['httpClientOptions'])) {
            $httpClientOptions = $options['httpClientOptions'];
        } elseif (isset($options['http_client_options'])) {
            $httpClientOptions = $options['http_client_options'];
        }

        // If we have an OAuth access token, use the HTTP client it provides
        if ($accessToken && is_array($accessToken)
            && (isset($accessToken['token']) && isset($accessToken['secret']))
        ) {
            $token = new OAuth\Token\Access();
            $token->setToken($accessToken['token']);
            $token->setTokenSecret($accessToken['secret']);
            $accessToken = $token;
        }
        if ($accessToken && $accessToken instanceof OAuth\Token\Access) {
            $oauthOptions['token'] = $accessToken;
            $this->setHttpClient($accessToken->getHttpClient(
                $oauthOptions,
                static::OAUTH_BASE_URI,
                $httpClientOptions
            ));
            return;
        }

        // See if we were passed an http client
        if (isset($options['httpClient']) && null === $httpClient) {
            $httpClient = $options['httpClient'];
        } elseif (isset($options['http_client']) && null === $httpClient) {
            $httpClient = $options['http_client'];
        }

        if ($httpClient instanceof Http\Client) {
            $this->httpClient = $httpClient;
        } else {
            $this->setHttpClient(new Http\Client(null, $httpClientOptions));
        }

        // Set the OAuth consumer
        if ($consumer === null) {
            $consumer = new OAuth\Consumer($oauthOptions);
        }
        $this->oauthConsumer = $consumer;
    }

    /**
     * Proxy service methods.
     *
     * Allows performing method proxy calls via property access; see {@link __call}
     * for more details.
     *
     * Valid `$type` values currently include:
     *
     * - account
     * - application
     * - blocks
     * - directmessages
     * - favorites
     * - followers
     * - friends
     * - friendships
     * - lists
     * - search
     * - statuses
     * - users
     *
     * @throws Exception\DomainException If method not in method types list
     */
    public function __get(string $type) : self
    {
        $type = strtolower($type);
        $type = str_replace('_', '', $type);
        if (! in_array($type, $this->methodTypes)) {
            throw new Exception\DomainException(
                'Invalid method type "' . $type . '"'
            );
        }
        $this->methodType = $type;
        return $this;
    }

    /**
     * Proxy method calls.
     *
     * Acts as a method proxy in two ways.
     *
     * First, if the method exists on the `$oauthConsumer` property, it will call
     * it using the provided parameters.
     *
     * Second, it will proxy to specific Twitter API segments. If the user requests
     * a property of this class that maps to a known method type ({@link $methodTypes}),
     * that value is stored in `$methodType` and the current instance is returned;
     * method overloading then checks to see if `$methodType` + `$method` is a known
     * method of this class, and, if so, calls it. (Underscores are stripped in both
     * processes.)
     *
     * As examples:
     *
     * <code>
     * $response = $twitter->search->tweets('#php');
     * $response = $twitter->users->search('zfdevteam');
     * $response = $twitter->users->search('zfdevteam');
     * $response = $twitter->statuses->mentions_timeline('zfdevteam');
     * </code>
     *
     * @return mixed
     * @throws Exception\BadMethodCallException if unable to find method
     */
    public function __call(string $method, array $params)
    {
        if (method_exists($this->oauthConsumer, $method)) {
            $return = $this->oauthConsumer->$method(...$params);
            if ($return instanceof OAuth\Token\Access) {
                $this->setHttpClient($return->getHttpClient($this->options));
            }
            return $return;
        }

        if (empty($this->methodType)) {
            throw new Exception\BadMethodCallException(
                'Invalid method "' . $method . '"'
            );
        }

        $test = str_replace('_', '', strtolower($method));
        $test = $this->methodType . $test;
        if (! method_exists($this, $test)) {
            throw new Exception\BadMethodCallException(
                'Invalid method "' . $test . '"'
            );
        }

        return $this->$test(...$params);
    }

    /**
     * Set HTTP client.
     */
    public function setHttpClient(Http\Client $client) : self
    {
        $this->httpClient = $client;
        $this->httpClient->setHeaders(['Accept-Charset' => 'ISO-8859-1,utf-8']);
        return $this;
    }

    /**
     * Get the HTTP client.
     *
     * Lazy loads one if none present.
     */
    public function getHttpClient() : Http\Client
    {
        if (null === $this->httpClient) {
            $this->setHttpClient(new Http\Client());
        }
        return $this->httpClient;
    }

    /**
     * Retrieve username.
     */
    public function getUsername() : ?string
    {
        return $this->username;
    }

    /**
     * Set username.
     */
    public function setUsername(string $value) : self
    {
        $this->username = $value;
        return $this;
    }

    /**
     * Checks for an authorised state.
     */
    public function isAuthorised(Http\Client $client = null) : bool
    {
        $client = $client ?: $this->getHttpClient();
        if ($client instanceof OAuth\Client) {
            return true;
        }
        return false;
    }

    /**
     * Performs an HTTP GET request to the $path.
     *
     * Used internally for all Twitter API GET calls, this method returns a
     * Response instance from a request made to API_BASE_URL + $path + '.json';
     * if any $query parameters are provided, they are appended as the query
     * string when the request is made.
     *
     * Call this method if you wish to make a GET request to an endpoint that
     * does not yet have a corresponding method in this class. As an example:
     *
     * <code>
     * $response = $twitter->get('friends/list', ['screen_name' => 'zfdevteam']);
     * foreach ($response->users as $user) {
     *     printf("- %s (%s)\n", $user->name, $user->screen_name);
     * }
     * </code>
     *
     * @param array $query Array of query string arguments
     * @throws Http\Client\Exception\ExceptionInterface
     */
    public function get(string $path, array $query = []) : Response
    {
        $client = $this->getHttpClient();
        $this->init($path, $client);
        $client->setParameterGet($query);
        $client->setMethod(Http\Request::METHOD_GET);
        $response = $client->send();
        return new Response($response);
    }

    /**
     * Performs an HTTP POST request to $path.
     *
     * Used internally for all Twitter API POST calls, this method returns a
     * Response instance from a request made to API_BASE_URL + $path + '.json'.
     *
     * $data may be:
     *
     * - null, in which case no request body is sent.
     * - a string, in which case the value is used for the request body.
     * - an array or object, in which case the value is passed to json_encode(),
     *   and the resultant string passed as the request body.
     *
     * Call this method if you wish to make a POST request to an endpoint that
     * does not yet have a corresponding method in this class. As an example:
     *
     * <code>
     * $response = $twitter->post('collections/entries/add', [
     *     'id' => $collectionId,
     *     'tweet_id' => $statusId,
     * ]);
     * if ($response->isError()) {
     *     echo "Error adding tweet to collection:\n";
     *     foreach ($response->response->errors as $error) {
     *         printf("- %s: %s\n", $error->change->tweet_id, $error->reason);
     *     }
     * }
     * </code>
     *
     * @param string $path
     * @param null|string|array|\stdClass $data Raw data to send
     * @throws Http\Client\Exception\ExceptionInterface
     */
    public function post(string $path, $data = null) : Response
    {
        $client = $this->getHttpClient();
        $this->init($path, $client);
        $response = $this->performPost(
            Http\Request::METHOD_POST,
            $data,
            $client,
            in_array($path, self::PATHS_JSON_PAYLOAD, true)
        );
        return new Response($response);
    }

    /**
     * Verify account credentials.
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function accountVerifyCredentials() : Response
    {
        return $this->get('account/verify_credentials');
    }

    /**
     * Returns the number of api requests you have left per hour.
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function applicationRateLimitStatus() : Response
    {
        return $this->get('application/rate_limit_status');
    }

    /**
     * Blocks the user specified in the ID parameter as the authenticating user.
     *
     * Destroys a friendship to the blocked user if it exists.
     *
     * @param int|string $id The ID or screen name of a user to block.
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function blocksCreate($id) : Response
    {
        $path     = 'blocks/create';
        $params   = $this->createUserParameter($id, []);
        return $this->post($path, $params);
    }

    /**
     * Un-blocks the user specified in the ID parameter for the authenticating user.
     *
     * @param int|string $id The ID or screen_name of the user to un-block.
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function blocksDestroy($id) : Response
    {
        $path   = 'blocks/destroy';
        $params = $this->createUserParameter($id, []);
        return $this->post($path, $params);
    }

    /**
     * Returns an array of user ids that the authenticating user is blocking.
     *
     * @param int $cursor Optional. Specifies the cursor position at which to
     *     begin listing ids; defaults to first "page" of results.
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function blocksIds(int $cursor = -1) : Response
    {
        $path = 'blocks/ids';
        return $this->get($path, ['cursor' => $cursor]);
    }

    /**
     * Returns an array of user objects that the authenticating user is blocking.
     *
     * @param int $cursor Optional. Specifies the cursor position at which to
     *     begin listing ids; defaults to first "page" of results.
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function blocksList(int $cursor = -1) : Response
    {
        $path = 'blocks/list';
        return $this->get($path, ['cursor' => $cursor]);
    }

    /**
     * Destroy a direct message
     *
     * @param  string|int $id ID of message to destroy.
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function directMessagesDestroy($id) : Response
    {
        $path   = 'direct_messages/destroy';
        $params = ['id' => $this->validInteger($id)];
        return $this->post($path, $params);
    }

    /**
     * Retrieve direct messages for the current user.
     *
     * $options may include one or more of the following keys:
     *
     * - count: return page X of results
     * - since_id: return statuses only greater than the one specified
     * - max_id: return statuses with an ID less than (older than) or equal to that specified
     * - include_entities: setting to false will disable embedded entities
     * - skip_status:setting to true, "t", or 1 will omit the status in returned users
     *
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function directMessagesMessages(array $options = []) : Response
    {
        $path   = 'direct_messages';
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'count':
                    $params['count'] = (int) $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                case 'skip_status':
                    $params['skip_status'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Send a direct message to a user.
     *
     * Proxies to `directMessagesEventsNew()`, as the `direct_messages/new`
     * path is deprecated.
     *
     * @param  int|string $user User to whom to send message
     * @throws Exception\InvalidArgumentException if message is empty
     * @throws Exception\OutOfRangeException if message is too long
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function directMessagesNew($user, string $text, array $extraParams = []) : Response
    {
        return $this->directMessagesEventsNew($user, $text, $extraParams);
    }

    /**
     * Send a direct message to a user.
     *
     * If `$extraParams` contains a `media_id` parameter, that value will be
     * used to provide a media attachment for the message.
     *
     * @param  int|string $user User to whom to send message
     * @throws Exception\InvalidArgumentException if message is empty
     * @throws Exception\OutOfRangeException if message is too long
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function directMessagesEventsNew($user, string $text, array $extraParams = []) : Response
    {
        $path = 'direct_messages/events/new';

        $len = strlen(utf8_decode($text));
        if (0 === $len) {
            throw new Exception\InvalidArgumentException(
                'Direct message must contain at least one character'
            );
        }

        if (10000 < $len) {
            throw new Exception\InvalidArgumentException(
                'Direct message must be no more than 10000 characters'
            );
        }

        if (! $this->validInteger($user)) {
            $response = $this->usersShow($user);
            if (! $response->isSuccess()) {
                throw new Exception\InvalidArgumentException(
                    'Invalid user provided; must be a Twitter user ID or screen name'
                );
            }
            $user = $response->id_str;
        }

        $params = [
            'type' => 'message_create',
            'message_create' => [
                'target' => [
                    'recipient_id' => $user,
                ],
                'message_data' => [
                    'text' => $text,
                ],
            ],
        ];

        if (isset($extraParams['media_id'])) {
            $params['message_create']['message_data']['attachment'] = [
                'type' => 'media',
                'media' => [
                    'id' => $extraParams['media_id'],
                ],
            ];
        }

        return $this->post($path, ['event' => $params]);
    }

    /**
     * Retrieve list of direct messages sent by current user.
     *
     * $options may include one or more of the following keys:
     *
     * - count: return page X of results
     * - page: return starting at page
     * - since_id: return statuses only greater than the one specified
     * - max_id: return statuses with an ID less than (older than) or equal to that specified
     * - include_entities: setting to false will disable embedded entities
     *
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function directMessagesSent(array $options = []) : Response
    {
        $path   = 'direct_messages/sent';
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'count':
                    $params['count'] = (int) $value;
                    break;
                case 'page':
                    $params['page'] = (int) $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Mark a status as a favorite.
     *
     * @param int|string $id Status ID you want to mark as a favorite
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function favoritesCreate($id) : Response
    {
        $path   = 'favorites/create';
        $params = ['id' => $this->validInteger($id)];
        return $this->post($path, $params);
    }

    /**
     * Remove a favorite.
     *
     * @param int|string $id Status ID you want to de-list as a favorite
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function favoritesDestroy($id) : Response
    {
        $path   = 'favorites/destroy';
        $params = ['id' => $this->validInteger($id)];
        return $this->post($path, $params);
    }

    /**
     * Fetch favorites.
     *
     * $options may contain one or more of the following:
     *
     * - user_id: Id of a user for whom to fetch favorites
     * - screen_name: Screen name of a user for whom to fetch favorites
     * - count: number of tweets to attempt to retrieve, up to 200
     * - since_id: return results only after the specified tweet id
     * - max_id: return results with an ID less than (older than) or equal to the specified ID
     * - include_entities: when set to false, entities member will be omitted
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function favoritesList(array $options = []) : Response
    {
        $path = 'favorites/list';
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'user_id':
                    $params['user_id'] = $this->validInteger($value);
                    break;
                case 'screen_name':
                    $params['screen_name'] = $value;
                    break;
                case 'count':
                    $params['count'] = (int) $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Get a list of up to 5000 follower IDs.
     *
     * Get a list of up to 5000 follower ids for the logged in account or for the
     * screen name you pass in. Returns the next cursor if there are more to be
     * returned.
     *
     * @param  int|string $id
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function followersIds($id, array $params = []) : Response
    {
        $path = 'followers/ids';
        $params = $this->createUserParameter($id, $params);
        return $this->get($path, $params);
    }

    /**
     * Returns a list of IDs of the current logged in user's friends or the
     * friends of the screen name passed in as
     * part of the parameters array.
     *
     * Returns the next cursor if there are more to be returned.
     *
     * @param int|string $id
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function friendsIds($id, array $params = []) : Response
    {
        $path = 'friends/ids';
        $params = $this->createUserParameter($id, $params);
        return $this->get($path, $params);
    }

    /**
     * Create friendship.
     *
     * $params are additional parameters to pass to the API, and  may include
     * any or all of the following:
     *
     * - user_id
     * - screen_name
     * - follow
     *
     * @param int|string $id User ID or name of new friend
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function friendshipsCreate($id, array $params = []) : Response
    {
        $path    = 'friendships/create';
        $params  = $this->createUserParameter($id, $params);
        $allowed = [
            'user_id'     => null,
            'screen_name' => null,
            'follow'      => null,
        ];
        $params = array_intersect_key($params, $allowed);
        return $this->post($path, $params);
    }

    /**
     * Get a list of the friends that the logged in user has.
     *
     * Returns the next cursor if there are more to be returned.
     *
     * $id may be one of any of the following:
     *
     * - a single user identifier
     * - a single screen name
     * - a list of user identifiers
     * - a list of screen names
     *
     * @param int|string|array $id
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function friendshipsLookup($id, array $params = []) : Response
    {
        $path = 'friendships/lookup';
        $params = $this->createUserListParameter($id, $params, __METHOD__);
        return $this->get($path, $params);
    }

    /**
     * Destroy friendship.
     *
     * @param  int|string $id User ID or name of friend to remove
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function friendshipsDestroy($id) : Response
    {
        $path   = 'friendships/destroy';
        $params = $this->createUserParameter($id, []);
        return $this->post($path, $params);
    }

    /**
     * Get a list of the users belonging to a given list
     *
     * Returns the next cursor if there are more to be returned.
     *
     * If the $listIdOrSlug represents a list's slug, then one of the following
     * parameters MUST be present:
     *
     * - `owner_id`, a valid integer identifier of a Twitter user
     * - `owner_screen_name`, a valid string identifier of a Twitter user
     *
     * If either are missing, or malformed, an exception is raised.
     *
     * @param int|string $listIdOrSlug
     * @throws Exception\InvalidArgumentException if $listIdOrSlug is a string
     *     slug and neither the `owner_id` nor the `owner_screen_name` are
     *     provided in `$params`.
     * @throws Exception\InvalidArgumentException if $listIdOrSlug is a string
     *     slug and the `owner_id` provided is not a valid integer.
     * @throws Exception\InvalidArgumentException if $listIdOrSlug is a string
     *     slug and the `owner_screen_name` provided is not valid.
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function listsMembers($listIdOrSlug, array $params = []) : Response
    {
        $path = 'lists/members';

        if (0 !== $this->validInteger($listIdOrSlug)) {
            $params['list_id'] = $listIdOrSlug;
            return $this->get($path, $params);
        }

        $params['slug'] = $listIdOrSlug;

        if (! array_key_exists('owner_id', $params) && ! array_key_exists('owner_screen_name', $params)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s was provided a list slug, but is missing owner info; '
                . 'please provide one of either the "owner_id" or '
                . '"owner_screen_name" parameters when calling the method',
                __METHOD__
            ));
        }

        if (array_key_exists('owner_id', $params)) {
            if (0 === $this->validInteger($params['owner_id'])) {
                throw new Exception\InvalidArgumentException(sprintf(
                    '%s was provided a list slug, but an invalid owner_id parameter; '
                    . 'must be an integer',
                    __METHOD__
                ));
            }
            return $this->get($path, $params);
        }

        if (! is_string($params['owner_screen_name'])) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s was provided a list slug, but an invalid owner_screen_name parameter; '
                . 'must be an integer',
                __METHOD__
            ));
        }

        try {
            $this->validateScreenName($params['owner_screen_name']);
        } catch (Exception\InvalidArgumentException $e) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s was provided a list slug, but an invalid owner_screen_name parameter; '
                . 'must be an integer',
                __METHOD__
            ), 0, $e);
        }
        return $this->get($path, $params);
    }

    /**
     * Get a list of the lists that the logged in user is a member of.
     *
     * Returns the next cursor if there are more to be returned.
     *
     * @param int|string $id
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function listsMemberships($id, array $params = []) : Response
    {
        $path = 'lists/memberships';
        $params = $this->createUserParameter($id, $params);
        return $this->get($path, $params);
    }

    /**
     * Returns the subscribers of the specified list. Private list subscribers
     * will only be shown if the authenticated user owns the specified list.
     *
     * Returns the next cursor if there are more to be returned.
     *
     * @param int|string $id
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function listsSubscribers($id, array $params = []) : Response
    {
        $path = 'lists/subscribers';
        $params = $this->createUserParameter($id, $params);
        return $this->get($path, $params);
    }

    /**
     * Search tweets.
     *
     * $options may include any of the following:
     *
     * - geocode: a string of the form "latitude, longitude, radius"
     * - lang: restrict tweets to the two-letter language code
     * - locale: query is in the given two-letter language code
     * - result_type: what type of results to receive: mixed, recent, or popular
     * - count: number of tweets to return per page; up to 100
     * - until: return tweets generated before the given date
     * - since_id: return resutls with an ID greater than (more recent than) the given ID
     * - max_id: return results with an ID less than (older than) the given ID
     * - include_entities: whether or not to include embedded entities
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function searchTweets(string $query, array $options = []) : Response
    {
        $path = 'search/tweets';

        $len = strlen(utf8_decode($query));
        if (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Query must contain at least one character'
            );
        }

        $params = ['q' => $query];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'geocode':
                    if (substr_count($value, ',') !== 2) {
                        throw new Exception\InvalidArgumentException(
                            '"geocode" must be of the format "latitude,longitude,radius"'
                        );
                    }
                    list($latitude, $longitude, $radius) = explode(',', $value, 3);
                    $radius = trim($radius);
                    if (! preg_match('/^\d+(mi|km)$/', $radius)) {
                        throw new Exception\InvalidArgumentException(
                            'Radius segment of "geocode" must be of the format "[unit](mi|km)"'
                        );
                    }
                    $latitude  = (float) $latitude;
                    $longitude = (float) $longitude;
                    $params['geocode'] = $latitude . ',' . $longitude . ',' . $radius;
                    break;
                case 'lang':
                    if (strlen($value) > 2) {
                        throw new Exception\InvalidArgumentException(
                            'Query language must be a 2 character string'
                        );
                    }
                    $params['lang'] = strtolower($value);
                    break;
                case 'locale':
                    if (strlen($value) > 2) {
                        throw new Exception\InvalidArgumentException(
                            'Query locale must be a 2 character string'
                        );
                    }
                    $params['locale'] = strtolower($value);
                    break;
                case 'result_type':
                    $value = strtolower($value);
                    if (! in_array($value, ['mixed', 'recent', 'popular'])) {
                        throw new Exception\InvalidArgumentException(
                            'result_type must be one of "mixed", "recent", or "popular"'
                        );
                    }
                    $params['result_type'] = $value;
                    break;
                case 'count':
                    $value = (int) $value;
                    if (1 > $value || 100 < $value) {
                        throw new Exception\InvalidArgumentException(
                            'count must be between 1 and 100'
                        );
                    }
                    $params['count'] = $value;
                    break;
                case 'until':
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        throw new Exception\InvalidArgumentException(
                            '"until" must be a date in the format YYYY-MM-DD'
                        );
                    }
                    $params['until'] = $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Destroy a status message.
     *
     * @param int|string $id ID of status to destroy
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesDestroy($id) : Response
    {
        $path = 'statuses/destroy/' . $this->validInteger($id);
        return $this->post($path);
    }

    /**
     * Home Timeline Status.
     *
     * $options may include one or more of the following keys:
     *
     * - count: number of tweets to attempt to retrieve, up to 200
     * - since_id: return results only after the specified tweet id
     * - max_id: return results with an ID less than (older than) or equal to the specified ID
     * - trim_user: when set to true, "t", or 1, user object in tweets will include only author's ID.
     * - contributor_details: when set to true, includes screen_name of each contributor
     * - include_entities: when set to false, entities member will be omitted
     * - exclude_replies: when set to true, will strip replies appearing in the timeline
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesHomeTimeline(array $options = []) : Response
    {
        $path = 'statuses/home_timeline';
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'count':
                    $params['count'] = (int) $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'tweet_mode':
                    unset($params[$key]);
                    $params['tweet_mode'] = 'extended';
                    break;
                case 'trim_user':
                    if (in_array($value, [true, 'true', 't', 1, '1'])) {
                        $value = true;
                    } else {
                        $value = false;
                    }
                    $params['trim_user'] = $value;
                    break;
                case 'contributor_details':
                    $params['contributor_details'] = (bool) $value;
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                case 'exclude_replies':
                    $params['exclude_replies'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Get metions.
     *
     * $options may include one or more of the following keys:
     *
     * - count: number of tweets to attempt to retrieve, up to 200
     * - since_id: return results only after the specified tweet id
     * - max_id: return results with an ID less than (older than) or equal to the specified ID
     * - trim_user: when set to true, "t", or 1, user object in tweets will include only author's ID.
     * - contributor_details: when set to true, includes screen_name of each contributor
     * - include_entities: when set to false, entities member will be omitted
     *
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesMentionsTimeline(array $options = []) : Response
    {
        $path   = 'statuses/mentions_timeline';
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'count':
                    $params['count'] = (int) $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'tweet_mode':
                    unset($params[$key]);
                    $params['tweet_mode'] = 'extended';
                    break;
                case 'trim_user':
                    if (in_array($value, [true, 'true', 't', 1, '1'])) {
                        $value = true;
                    } else {
                        $value = false;
                    }
                    $params['trim_user'] = $value;
                    break;
                case 'contributor_details:':
                    $params['contributor_details:'] = (bool) $value;
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Random sampling of public statuses.
     *
     * Note: this method is not in the current Twitter API documentation, and
     * may not work currently.
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesSample() : Response
    {
        $path = 'statuses/sample';
        return $this->get($path);
    }

    /**
     * Show a single status.
     *
     * $options is an array of additional data to pass to the API in order to
     * detail how to return results; it may include any or all of the following
     * options:
     *
     * - tweet_mode: currently, only allows "extended", to provide maximum tweet detail
     * - include_entities: whether or not to return media entities
     * - trim_user: whether or not to return user data with each tweet
     * - include_my_retweet: whether or not to return statuses that are your own retweets
     *
     * @param  int|string $id Id of status to show
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesShow($id, array $options = []) : Response
    {
        $path = 'statuses/show/' . $this->validInteger($id);
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'tweet_mode':
                    unset($params[$key]);
                    $params['tweet_mode'] = 'extended';
                    break;
                case 'include_entities':
                    $params[strtolower($key)] = (bool) $value;
                    break;
                case 'trim_user':
                    $params[strtolower($key)] = (bool) $value;
                    break;
                case 'include_my_retweet':
                    $params[strtolower($key)] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Update user's current status.
     *
     * $extraAttributes currently supports the following options:
     *
     * - media_ids: an array of media identifiers as returned by uploading
     *   media via the Twitter API, and which should be attached to the tweet.
     *
     * @param  string $status
     * @param  null|int|string $inReplyToStatusId
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\OutOfRangeException if message is too long
     * @throws Exception\InvalidArgumentException if message is empty
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesUpdate(string $status, $inReplyToStatusId = null, $extraAttributes = []) : Response
    {
        $path = 'statuses/update';
        $len = strlen(utf8_decode($status));
        if ($len > self::STATUS_MAX_CHARACTERS) {
            throw new Exception\OutOfRangeException(sprintf(
                'Status must be no more than %d characters in length; received %d',
                self::STATUS_MAX_CHARACTERS,
                $len
            ));
        } elseif (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Status must contain at least one character'
            );
        }

        $params = ['status' => $status];

        if (isset($extraAttributes['media_ids'])
            && is_array($extraAttributes['media_ids'])
            && ! empty($extraAttributes['media_ids'])
        ) {
            $params['media_ids'] = implode(',', $extraAttributes['media_ids']);
        }

        $inReplyToStatusId = $this->validInteger($inReplyToStatusId);
        if ($inReplyToStatusId) {
            $params['in_reply_to_status_id'] = $inReplyToStatusId;
        }

        return $this->post($path, $params);
    }

    /**
     * User Timeline.
     *
     * $options may include one or more of the following keys
     *
     * - user_id: Id of a user for whom to fetch favorites
     * - screen_name: Screen name of a user for whom to fetch favorites
     * - count: number of tweets to attempt to retrieve, up to 200
     * - since_id: return results only after the specified tweet id
     * - max_id: return results with an ID less than (older than) or equal to the specified ID
     * - trim_user: when set to true, "t", or 1, user object in tweets will include only author's ID.
     * - exclude_replies: when set to true, will strip replies appearing in the timeline
     * - contributor_details: when set to true, includes screen_name of each contributor
     * - include_rts: when set to false, will strip native retweets
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function statusesUserTimeline(array $options = []) : Response
    {
        $path = 'statuses/user_timeline';
        $params = [];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'user_id':
                    $params['user_id'] = $this->validInteger($value);
                    break;
                case 'screen_name':
                    $params['screen_name'] = $this->validateScreenName($value);
                    break;
                case 'count':
                    $params['count'] = (int) $value;
                    break;
                case 'since_id':
                    $params['since_id'] = $this->validInteger($value);
                    break;
                case 'max_id':
                    $params['max_id'] = $this->validInteger($value);
                    break;
                case 'tweet_mode':
                    unset($params[$key]);
                    $params['tweet_mode'] = 'extended';
                    break;
                case 'trim_user':
                    if (in_array($value, [true, 'true', 't', 1, '1'])) {
                        $value = true;
                    } else {
                        $value = false;
                    }
                    $params['trim_user'] = $value;
                    break;
                case 'contributor_details:':
                    $params['contributor_details:'] = (bool) $value;
                    break;
                case 'exclude_replies':
                    $params['exclude_replies'] = (bool) $value;
                    break;
                case 'include_rts':
                    $params['include_rts'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }

    /**
     * Pass in one or more twitter IDs and it will return a list of user objects.
     *
     * This is the most effecient way of gathering bulk user data.
     *
     * $id may be one of any of the following:
     *
     * - a single user identifier
     * - a single screen name
     * - a list of user identifiers
     * - a list of screen names
     *
     * @param int|string|array $id
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function usersLookup($id, array $params = []) : Response
    {
        $path = 'users/lookup';
        $params = $this->createUserListParameter($id, $params, __METHOD__);
        return $this->post($path, $params);
    }

    /**
     * Search users.
     *
     * $options may include any of the following:
     *
     * - page: the page of results to retrieve
     * - count: the number of users to retrieve per page; max is 20
     * - include_entities: if set to boolean true, include embedded entities
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function usersSearch(string $query, array $options = []) : Response
    {
        $path = 'users/search';

        $len = strlen(utf8_decode($query));
        if (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Query must contain at least one character'
            );
        }

        $params = ['q' => $query];
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'count':
                    $value = (int) $value;
                    if (1 > $value || 20 < $value) {
                        throw new Exception\InvalidArgumentException(
                            'count must be between 1 and 20'
                        );
                    }
                    $params['count'] = $value;
                    break;
                case 'page':
                    $params['page'] = (int) $value;
                    break;
                case 'include_entities':
                    $params['include_entities'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        return $this->get($path, $params);
    }


    /**
     * Show extended information on a user.
     *
     * @param  int|string $id User ID or name
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     */
    public function usersShow($id) : Response
    {
        $path     = 'users/show';
        $params   = $this->createUserParameter($id, []);
        return $this->get($path, $params);
    }

    /**
     * Initialize HTTP authentication
     *
     * @throws Exception\DomainException if unauthorised
     */
    public function init(string $path, Http\Client $client) : void
    {
        if (! $this->isAuthorised($client) && $this->getUsername() !== null) {
            throw new Exception\DomainException(
                'Twitter session is unauthorised. You need to initialize '
                . __CLASS__ . ' with an OAuth Access Token or use '
                . 'its OAuth functionality to obtain an Access Token before '
                . 'attempting any API actions that require authorisation'
            );
        }

        // Reset on every request to prevent parameters leaking between them.
        $client->resetParameters();
        $client->setUri(static::API_BASE_URI . $path . '.json');

        if (null === $this->cookieJar) {
            $client->clearCookies();
            $this->cookieJar = $client->getCookies();
            return;
        }

        $client->setCookies($this->cookieJar);
    }

    /**
     * Protected function to validate a status or user identifier.
     *
     * If the value consists of solely digits, it is valid, and will be
     * returned verbatim. Otherwise, a zero is returned.
     *
     * @param string|int $int
     */
    protected function validInteger($int) : int
    {
        if (is_int($int) && $int > -1) {
            return $int;
        }

        if (is_string($int) && preg_match('/^(\d+)$/', $int)) {
            return $int;
        }

        return 0;
    }

    /**
     * Validate a screen name using Twitter rules.
     *
     * @throws Exception\InvalidArgumentException
     */
    protected function validateScreenName(string $name) : string
    {
        if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_]{1,15}$/', $name)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Screen name, "%s" should only contain alphanumeric characters and'
                . ' underscores, and not exceed 15 characters.',
                $name
            ));
        }
        return $name;
    }

    /**
     * Perform a POST or PUT
     *
     * Performs a POST or PUT request. Any data provided is set in the HTTP
     * client. String data is pushed in as raw POST data; array or object data
     * is JSON-encoded before being passed to the request body.
     *
     * @param null|string|array|\stdClass $data Raw data to send
     * @param bool $asJson Whether or not the data should be submitted as JSON
     *     (vs form urlencoded, which is the default)
     */
    protected function performPost(string $method, $data, Http\Client $client, bool $asJson) : Http\Response
    {
        $client->setMethod($method);

        $asJson
            ? $this->prepareJsonPayloadForClient($client, $data)
            : $this->prepareFormPayloadForClient($client, $data);

        return $client->send();
    }

    /**
     * Create a parameter representing the user.
     *
     * Determines if $id is an integer, and, if so, sets the "user_id" parameter.
     * If not, assumes the $id is the "screen_name".
     *
     * Returns the $params with the appropriate key added.
     *
     * @param int|string $id
     * @param array $params
     * @throws Exception\InvalidArgumentException if the value is neither an integer nor a string
     */
    protected function createUserParameter($id, array $params) : array
    {
        if (! is_string($id) && ! is_int($id)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '$id must be an integer or a string, received %s',
                is_object($id) ? get_class($id) : gettype($id)
            ));
        }

        if (0 !== $this->validInteger($id)) {
            $params['user_id'] = $id;
            return $params;
        }

        if (! is_string($id)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '$id must be an integer or a string, received %s',
                gettype($id)
            ));
        }

        $params['screen_name'] = $this->validateScreenName($id);
        return $params;
    }

    /**
     * Prepares a list of identifiers for use with endpoints accepting lists of users.
     *
     * The various `lookup` endpoints allow passing either:
     *
     * - a single user identifier
     * - a single screen name
     * - a list of user identifiers
     * - a list of screen names
     *
     * This method checks for each of these conditions. For scalar $ids, it
     * proxies to {@link createUserParameter}. Otherwise, it checks to ensure
     * that all values are of the same type. For identifiers, it then
     * concatenates the values and returns them in the `user_id` parameter.
     * For screen names, it validates them first, before concatenating and
     * returning them via the `screen_name` parameter.
     *
     * @param int|string|array $ids
     * @throws Exception\InvalidArgumentException for a non-int/string/array $ids value.
     * @throws Exception\InvalidArgumentException if an array of $ids exceeds 100 items.
     * @throws Exception\InvalidArgumentException if an array of $ids are not all of the same type.
     * @throws Exception\InvalidArgumentException if any screen name element is invalid.
     */
    protected function createUserListParameter($ids, array $params, string $context) : array
    {
        if (! is_array($ids)) {
            return $this->createUserParameter($ids, $params);
        }

        if (100 < count($ids)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Lists of identifier(s) or screen name(s) provided for %s; '
                . 'must contain no more than 100 items. '
                . 'Received %d',
                $context,
                count($ids)
            ));
        }

        $detectedType = array_reduce($ids, function ($detectedType, $id) {
            if (false === $detectedType) {
                return $detectedType;
            }

            $idType = 0 !== $this->validInteger($id)
                ? 'user_id'
                : 'screen_name';

            if (null === $detectedType) {
                return $idType;
            }

            return $detectedType === $idType
                ? $detectedType
                : false;
        }, null);

        if (false === $detectedType) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid identifier(s) or screen name(s) provided for %s; '
                . 'all values must either be identifiers OR screen names. '
                . 'You cannot provide items of both types.',
                $context
            ));
        }

        if ($detectedType === 'user_id') {
            $params['user_id'] = implode(',', $ids);
            return $params;
        }

        array_walk($ids, Closure::fromCallable([$this, 'validateScreenName']));
        $params['screen_name'] = implode(',', $ids);
        return $params;
    }

    /**
     * Prepare a JSON payload for the HTTP client.
     */
    private function prepareJsonPayloadForClient(Http\Client $client, $data)
    {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, $this->jsonFlags);
        }

        if (empty($data) || ! is_string($data)) {
            return;
        }

        $client->getRequest()
            ->getHeaders()
            ->addHeaderLine('Content-Type', 'application/json');

        $client->setRawBody($data);
    }

    /**
     * Prepare a form-url-encoded payload for the HTTP client.
     */
    private function prepareFormPayloadForClient(Http\Client $client, $data)
    {
        if (! is_array($data)) {
            return;
        }

        $client->setParameterPost($data);
    }
}
