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

use Traversable;
use Zend\Http;
use ZendOAuth as OAuth;
use Zend\Stdlib\ArrayUtils;
use Zend\Uri;

/**
 * @category   Zend
 * @package    Zend_Service
 * @subpackage Twitter
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
     * 246 is the current limit for a status message, 140 characters are displayed
     * initially, with the remainder linked from the web UI or client. The limit is
     * applied to a html encoded UTF-8 string (i.e. entities are counted in the limit
     * which may appear unusual but is a security measure).
     *
     * This should be reviewed in the future...
     */
    const STATUS_MAX_CHARACTERS = 246;

    /**
     * @var array
     */
    protected $cookieJar;

    /**
     * Date format for 'since' strings
     *
     * @var string
     */
    protected $dateFormat = 'D, d M Y H:i:s T';

    /**
     * @var Http\Client
     */
    protected $httpClient = null;

    /**
     * Current method type (for method proxying)
     *
     * @var string
     */
    protected $methodType;

    /**
     * Oauth Consumer
     *
     * @var OAuth\Consumer
     */
    protected $oauthConsumer = null;

    /**
     * Types of API methods
     *
     * @var array
     */
    protected $methodTypes = array(
        'account',
        'application',
        'blocks',
        'directmessages',
        'favorites',
        'friendships',
        'search',
        'statuses',
        'users',
    );

    /**
     * Options passed to constructor
     *
     * @var array
     */
    protected $options = array();

    /**
     * Username
     *
     * @var string
     */
    protected $username;

    /**
     * Constructor
     *
     * @param  null|array|Traversable $options
     * @param  null|OAuth\Consumer $consumer
     * @param  null|Http\Client $httpClient
     */
    public function __construct($options = null, OAuth\Consumer $consumer = null, Http\Client $httpClient = null)
    {
        if ($options instanceof Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        }
        if (!is_array($options)) {
            $options = array();
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

        $oauthOptions = array();
        if (isset($options['oauthOptions'])) {
            $oauthOptions = $options['oauthOptions'];
        } elseif (isset($options['oauth_options'])) {
            $oauthOptions = $options['oauth_options'];
        }
        $oauthOptions['siteUrl'] = static::OAUTH_BASE_URI;

        $httpClientOptions = array();
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
            $this->setHttpClient($accessToken->getHttpClient($oauthOptions, static::OAUTH_BASE_URI, $httpClientOptions));
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
     * Proxy service methods
     *
     * @param  string $type
     * @return Twitter
     * @throws Exception\DomainException If method not in method types list
     */
    public function __get($type)
    {
        $type = strtolower($type);
        $type = str_replace('_', '', $type);
        if (!in_array($type, $this->methodTypes)) {
            throw new Exception\DomainException(
                'Invalid method type "' . $type . '"'
            );
        }
        $this->methodType = $type;
        return $this;
    }

    /**
     * Method overloading
     *
     * @param  string $method
     * @param  array $params
     * @return mixed
     * @throws Exception\BadMethodCallException if unable to find method
     */
    public function __call($method, $params)
    {
        if (method_exists($this->oauthConsumer, $method)) {
            $return = call_user_func_array(array($this->oauthConsumer, $method), $params);
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
        if (!method_exists($this, $test)) {
            throw new Exception\BadMethodCallException(
                'Invalid method "' . $test . '"'
            );
        }

        return call_user_func_array(array($this, $test), $params);
    }

    /**
     * Set HTTP client
     *
     * @param Http\Client $client
     * @return self
     */
    public function setHttpClient(Http\Client $client)
    {
        $this->httpClient = $client;
        $this->httpClient->setHeaders(array('Accept-Charset' => 'ISO-8859-1,utf-8'));
        return $this;
    }

    /**
     * Get the HTTP client
     *
     * Lazy loads one if none present
     *
     * @return Http\Client
     */
    public function getHttpClient()
    {
        if (null === $this->httpClient) {
            $this->setHttpClient(new Http\Client());
        }
        return $this->httpClient;
    }

    /**
     * Retrieve username
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set username
     *
     * @param  string $value
     * @return self
     */
    public function setUsername($value)
    {
        $this->username = $value;
        return $this;
    }

    /**
     * Checks for an authorised state
     *
     * @return bool
     */
    public function isAuthorised()
    {
        if ($this->getHttpClient() instanceof OAuth\Client) {
            return true;
        }
        return false;
    }

    /**
     * Verify Account Credentials
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function accountVerifyCredentials()
    {
        $this->init();
        $response = $this->get('account/verify_credentials');
        return new Response($response);
    }

    /**
     * Returns the number of api requests you have left per hour.
     *
     * @todo   Have a separate payload object to represent rate limits
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function applicationRateLimitStatus()
    {
        $this->init();
        $response = $this->get('application/rate_limit_status');
        return new Response($response);
    }

    /**
     * Blocks the user specified in the ID parameter as the authenticating user.
     * Destroys a friendship to the blocked user if it exists.
     *
     * @param  integer|string $id       The ID or screen name of a user to block.
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function blocksCreate($id)
    {
        $this->init();
        $path     = 'blocks/create';
        $params   = $this->createUserParameter($id, array());
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Un-blocks the user specified in the ID parameter for the authenticating user
     *
     * @param  integer|string $id       The ID or screen_name of the user to un-block.
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function blocksDestroy($id)
    {
        $this->init();
        $path   = 'blocks/destroy';
        $params = $this->createUserParameter($id, array());
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Returns an array of user ids that the authenticating user is blocking
     *
     * @param  integer $cursor  Optional. Specifies the cursor position at which to begin listing ids; defaults to first "page" of results.
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function blocksIds($cursor = -1)
    {
        $this->init();
        $path = 'blocks/ids';
        $response = $this->get($path, array('cursor' => $cursor));
        return new Response($response);
    }

    /**
     * Returns an array of user objects that the authenticating user is blocking
     *
     * @param  integer $cursor  Optional. Specifies the cursor position at which to begin listing ids; defaults to first "page" of results.
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function blocksList($cursor = -1)
    {
        $this->init();
        $path = 'blocks/list';
        $response = $this->get($path, array('cursor' => $cursor));
        return new Response($response);
    }

    /**
     * Destroy a direct message
     *
     * @param  int $id ID of message to destroy
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function directMessagesDestroy($id)
    {
        $this->init();
        $path     = 'direct_messages/destroy';
        $params   = array('id' => $this->validInteger($id));
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Retrieve direct messages for the current user
     *
     * $options may include one or more of the following keys
     * - count: return page X of results
     * - since_id: return statuses only greater than the one specified
     * - max_id: return statuses with an ID less than (older than) or equal to that specified
     * - include_entities: setting to false will disable embedded entities
     * - skip_status:setting to true, "t", or 1 will omit the status in returned users
     *
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function directMessagesMessages(array $options = array())
    {
        $this->init();
        $path   = 'direct_messages';
        $params = array();
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
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Send a direct message to a user
     *
     * @param  int|string $user User to whom to send message
     * @param  string $text Message to send to user
     * @throws Exception\InvalidArgumentException if message is empty
     * @throws Exception\OutOfRangeException if message is too long
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function directMessagesNew($user, $text)
    {
        $this->init();
        $path = 'direct_messages/new';

        $len = iconv_strlen($text, 'UTF-8');
        if (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Direct message must contain at least one character'
            );
        } elseif (140 < $len) {
            throw new Exception\OutOfRangeException(
                'Direct message must contain no more than 140 characters'
            );
        }

        $params         = $this->createUserParameter($user, array());
        $params['text'] = $text;
        $response       = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Retrieve list of direct messages sent by current user
     *
     * $options may include one or more of the following keys
     * - count: return page X of results
     * - page: return starting at page
     * - since_id: return statuses only greater than the one specified
     * - max_id: return statuses with an ID less than (older than) or equal to that specified
     * - include_entities: setting to false will disable embedded entities
     *
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function directMessagesSent(array $options = array())
    {
        $this->init();
        $path   = 'direct_messages/sent';
        $params = array();
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
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Mark a status as a favorite
     *
     * @param  int $id Status ID you want to mark as a favorite
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function favoritesCreate($id)
    {
        $this->init();
        $path     = 'favorites/create';
        $params   = array('id' => $this->validInteger($id));
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Remove a favorite
     *
     * @param  int $id Status ID you want to de-list as a favorite
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function favoritesDestroy($id)
    {
        $this->init();
        $path     = 'favorites/destroy';
        $params   = array('id' => $this->validInteger($id));
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Fetch favorites
     *
     * $options may contain one or more of the following:
     * - user_id: Id of a user for whom to fetch favorites
     * - screen_name: Screen name of a user for whom to fetch favorites
     * - count: number of tweets to attempt to retrieve, up to 200
     * - since_id: return results only after the specified tweet id
     * - max_id: return results with an ID less than (older than) or equal to the specified ID
     * - include_entities: when set to false, entities member will be omitted
     *
     * @param  array $params
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function favoritesList(array $options = array())
    {
        $this->init();
        $path = 'favorites/list';
        $params = array();
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
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Create friendship
     *
     * @param  int|string $id User ID or name of new friend
     * @param  array $params Additional parameters to pass
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function friendshipsCreate($id, array $params = array())
    {
        $this->init();
        $path    = 'friendships/create';
        $params  = $this->createUserParameter($id, $params);
        $allowed = array(
            'user_id'     => null,
            'screen_name' => null,
            'follow'      => null,
        );
        $params = array_intersect_key($params, $allowed);
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Destroy friendship
     *
     * @param  int|string $id User ID or name of friend to remove
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function friendshipsDestroy($id)
    {
        $this->init();
        $path     = 'friendships/destroy';
        $params   = $this->createUserParameter($id, array());
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * Search tweets
     *
     * $options may include any of the following:
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
     * @param  string $query
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function searchTweets($query, array $options = array())
    {
        $this->init();
        $path = 'search/tweets';

        $len = iconv_strlen($query, 'UTF-8');
        if (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Query must contain at least one character'
            );
        }

        $params = array('q' => $query);
        foreach ($options as $key => $value) {
            switch (strtolower($key)) {
                case 'geocode':
                    if (!substr_count($value, ',') !== 2) {
                        throw new Exception\InvalidArgumentException(
                            '"geocode" must be of the format "latitude,longitude,radius"'
                        );
                    }
                    list($latitude, $longitude, $radius) = explode(',', $value);
                    $radius = trim($radius);
                    if (!preg_match('/^\d+(mi|km)$/', $radius)) {
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
                    if (!in_array($value, array('mixed', 'recent', 'popular'))) {
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
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
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
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Destroy a status message
     *
     * @param  int $id ID of status to destroy
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function statusesDestroy($id)
    {
        $this->init();
        $path = 'statuses/destroy/' . $this->validInteger($id);
        $response = $this->post($path);
        return new Response($response);
    }

    /**
     * Friend Timeline Status
     *
     * $options may include one or more of the following keys
     * - count: number of tweets to attempt to retrieve, up to 200
     * - since_id: return results only after the specified tweet id
     * - max_id: return results with an ID less than (older than) or equal to the specified ID
     * - trim_user: when set to true, "t", or 1, user object in tweets will include only author's ID.
     * - contributor_details: when set to true, includes screen_name of each contributor
     * - include_entities: when set to false, entities member will be omitted
     * - exclude_replies: when set to true, will strip replies appearing in the timeline
     *
     * @param  array $params
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function statusesHomeTimeline(array $options = array())
    {
        $this->init();
        $path = 'statuses/home_timeline';
        $params = array();
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
                case 'trim_user':
                    if (in_array($value, array(true, 'true', 't', 1, '1'))) {
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
                case 'exclude_replies':
                    $params['exclude_replies'] = (bool) $value;
                    break;
                default:
                    break;
            }
        }
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Get status replies
     *
     * $options may include one or more of the following keys
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
     * @return Response
     */
    public function statusesMentionsTimeline(array $options = array())
    {
        $this->init();
        $path   = 'statuses/mentions_timeline';
        $params = array();
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
                case 'trim_user':
                    if (in_array($value, array(true, 'true', 't', 1, '1'))) {
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
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Public Timeline status
     *
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function statusesSample()
    {
        $this->init();
        $path = 'statuses/sample';
        $response = $this->get($path);
        return new Response($response);
    }

    /**
     * Show a single status
     *
     * @param  int $id Id of status to show
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function statusesShow($id)
    {
        $this->init();
        $path = 'statuses/show/' . $this->validInteger($id);
        $response = $this->get($path);
        return new Response($response);
    }

    /**
     * Update user's current status
     *
     * @todo   Support additional parameters supported by statuses/update endpoint
     * @param  string $status
     * @param  null|int $inReplyToStatusId
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\OutOfRangeException if message is too long
     * @throws Exception\InvalidArgumentException if message is empty
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function statusesUpdate($status, $inReplyToStatusId = null)
    {
        $this->init();
        $path = 'statuses/update';
        $len = iconv_strlen(htmlspecialchars($status, ENT_QUOTES, 'UTF-8'), 'UTF-8');
        if ($len > self::STATUS_MAX_CHARACTERS) {
            throw new Exception\OutOfRangeException(
                'Status must be no more than '
                . self::STATUS_MAX_CHARACTERS
                . ' characters in length'
            );
        } elseif (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Status must contain at least one character'
            );
        }

        $params = array('status' => $status);
        $inReplyToStatusId = $this->validInteger($inReplyToStatusId);
        if ($inReplyToStatusId) {
            $params['in_reply_to_status_id'] = $inReplyToStatusId;
        }
        $response = $this->post($path, $params);
        return new Response($response);
    }

    /**
     * User Timeline status
     *
     * $options may include one or more of the following keys
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
     * @return Response
     */
    public function statusesUserTimeline(array $options = array())
    {
        $this->init();
        $path = 'statuses/user_timeline';
        $params = array();
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
                case 'trim_user':
                    if (in_array($value, array(true, 'true', 't', 1, '1'))) {
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
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Search users
     *
     * $options may include any of the following:
     * - page: the page of results to retrieve
     * - count: the number of users to retrieve per page; max is 20
     * - include_entities: if set to boolean true, include embedded entities
     *
     * @param  string $query
     * @param  array $options
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function usersSearch($query, array $options = array())
    {
        $this->init();
        $path = 'users/search';

        $len = iconv_strlen($query, 'UTF-8');
        if (0 == $len) {
            throw new Exception\InvalidArgumentException(
                'Query must contain at least one character'
            );
        }

        $params = array('q' => $query);
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
        $response = $this->get($path, $params);
        return new Response($response);
    }


    /**
     * Show extended information on a user
     *
     * @param  int|string $id User ID or name
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @throws Exception\DomainException if unable to decode JSON payload
     * @return Response
     */
    public function usersShow($id)
    {
        $this->init();
        $path     = 'users/show';
        $params   = $this->createUserParameter($id, array());
        $response = $this->get($path, $params);
        return new Response($response);
    }

    /**
     * Initialize HTTP authentication
     *
     * @return void
     * @throws Exception\DomainException if unauthorised
     */
    protected function init()
    {
        if (!$this->isAuthorised() && $this->getUsername() !== null) {
            throw new Exception\DomainException(
                'Twitter session is unauthorised. You need to initialize '
                . __CLASS__ . ' with an OAuth Access Token or use '
                . 'its OAuth functionality to obtain an Access Token before '
                . 'attempting any API actions that require authorisation'
            );
        }
        $client = $this->getHttpClient();
        $client->resetParameters();
        if (null === $this->cookieJar) {
            $client->clearCookies();
            $this->cookieJar = $client->getCookies();
        } else {
            $client->setCookies($this->cookieJar);
        }
    }

    /**
     * Protected function to validate that the integer is valid or return a 0
     *
     * @param  $int
     * @throws Http\Client\Exception\ExceptionInterface if HTTP request fails or times out
     * @return integer
     */
    protected function validInteger($int)
    {
        if (preg_match("/(\d+)/", $int)) {
            return $int;
        }
        return 0;
    }

    /**
     * Validate a screen name using Twitter rules
     *
     * @param string $name
     * @return string
     * @throws Exception\InvalidArgumentException
     */
    protected function validateScreenName($name)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,20}$/', $name)) {
            throw new Exception\InvalidArgumentException(
                'Screen name, "' . $name
                . '" should only contain alphanumeric characters and'
                . ' underscores, and not exceed 15 characters.');
        }
        return $name;
    }

    /**
     * Call a remote REST web service URI
     *
     * @param  string $path The path to append to the URI
     * @param  Http\Client $client
     * @throws Client\Exception\UnexpectedValueException
     * @return void
     */
    protected function prepare($path, Http\Client $client)
    {
        $client->setUri(static::API_BASE_URI . $path . '.json');

        /**
         * Do this each time to ensure oauth calls do not inject new params
         */
        $client->resetParameters();
    }

    /**
     * Performs an HTTP GET request to the $path.
     *
     * @param string $path
     * @param array  $query Array of GET parameters
     * @throws Http\Client\Exception\ExceptionInterface
     * @return Http\Response
     */
    protected function get($path, array $query = array())
    {
        $client = $this->getHttpClient();
        $this->prepare($path, $client);
        $client->setParameterGet($query);
        $client->setMethod(Http\Request::METHOD_GET);
        $response = $client->send();
        return $response;
    }

    /**
     * Performs an HTTP POST request to $path.
     *
     * @param string $path
     * @param mixed $data Raw data to send
     * @throws Http\Client\Exception\ExceptionInterface
     * @return Http\Response
     */
    protected function post($path, $data = null)
    {
        $client = $this->getHttpClient();
        $this->prepare($path, $client);
        return $this->performPost(Http\Request::METHOD_POST, $data, $client);
    }

    /**
     * Perform a POST or PUT
     *
     * Performs a POST or PUT request. Any data provided is set in the HTTP
     * client. String data is pushed in as raw POST data; array or object data
     * is pushed in as POST parameters.
     *
     * @param mixed $method
     * @param mixed $data
     * @return Http\Response
     */
    protected function performPost($method, $data, Http\Client $client)
    {
        if (is_string($data)) {
            $client->setRawData($data);
        } elseif (is_array($data) || is_object($data)) {
            $client->setParameterPost((array) $data);
        }
        $client->setMethod($method);
        return $client->send();
    }

    /**
     * Create a parameter representing the user
     *
     * Determines if $id is an integer, and, if so, sets the "user_id" parameter.
     * If not, assumes the $id is the "screen_name".
     *
     * @param  int|string $id
     * @param  array $params
     * @return array
     */
    protected function createUserParameter($id, array $params)
    {
        if ($this->validInteger($id)) {
            $params['user_id'] = $id;
            return $params;
        }

        $params['screen_name'] = $this->validateScreenName($id);
        return $params;
    }
}
