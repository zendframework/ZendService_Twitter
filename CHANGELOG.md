# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 3.0.0 - TBD

### Added

- [#39](https://github.com/zendframework/ZendService_Twitter/pull/39) adds
  support for PHP 7.1 and PHP 7.2.

- [#34](https://github.com/zendframework/ZendService_Twitter/pull/34) adds
  support for uploading media, via the new classes `ZendService\Twitter\Media`,
  `Image`, and `Video`. In each case, instantiate the appropriate class with the
  path to the image on the filesystem and the appropriate media type, and then
  call `upload()` with a zend-http client. The response will contain a
  `media_id` property, which you can then provide via the `media_ids` attribute
  when posting a status.

  ```php
  $image = new Image('data/logo.png', 'image/png');
  $response = $image->upload($client);
  
  $twitter->statusUpdate(
      'A post with an image',
      null,
      ['media_ids' => [$response->media_id]]
  );
  ```

- [#42](https://github.com/zendframework/ZendService_Twitter/pull/42) adds
  support for attaching media to direct messages. To do so, first upload an
  image, marking the image for a direct message; you can also optionally mark it
  to _share_, in which case you can re-use the returned media identifier with
  multiple direct messages:

  ```php
  $image = new Image(
      'data/logo.png',
      'image/png',
      $forDirectMessage = true,
      $shared = false
  );
  $upload = $image->upload($client);
  ```

  Once you have the media identifier, you can provide it via an extra parameter
  to the `directMessagesNew()` method, or the new `directMessagesEventNew()`
  method (which more closely corresponds to the API endpoint):

  ```php
  $twitter->directmessagesEventsNew(
      $user,
      $message,
      ['media_id' => $upload->id_str]
  );
  ```

  Direct messages only support one attachment at a time.

- [#37](https://github.com/zendframework/ZendService_Twitter/pull/37) and
  [#43](https://github.com/zendframework/ZendService_Twitter/pull/43) add
  support for returning media entities when calling `statusesShow()`. The method
  now allows a second, optional argument, `$options`, which may contain the
  following keys to pass to the Twitter API:

  - `tweet_mode`; if present, it will be passed as the value `extended`.
  - `include_entities`
  - `trim_user`
  - `include_my_retweet`

- [#34](https://github.com/zendframework/ZendService_Twitter/pull/34) adds
  support for Twitter's rate limit headers. Returned responses allow you to
  query them via `getRateLimit()`, and the returned
  `ZendService\Twitter\RateLimit` instance allows you to check the limit,
  remaining requests, and time to reset.

  ```php
  $response = $twitter->statusUpdate('A post');
  $rateLimit = $response->getRateLimit();
  if ($rateLimit->remaining === 0) {
      // Time to back off!
      sleep($rateLimit->reset); // seconds left until reset
  }
  ```

- [#29](https://github.com/zendframework/ZendService_Twitter/pull/29) adds
  support for:
  - retrieving a list of follower identifiers (`/followers/ids` API)
  - retrieving the lists to which the user belongs (`/lists/memberships` API)
  - retrieving the friends of the user (`/friendships/lookup` API)
  - retrieving full user data on a list of users (`/users/lookup` API)
  - retrieving the identifiers for friends of the user (`/friends/ids` API)

- [#31](https://github.com/zendframework/ZendService_Twitter/pull/31) adds
  support for PHP 7.

### Changed

- [#41](https://github.com/zendframework/ZendService_Twitter/pull/41) modifies
  how the `Twitter` class sends `POST` requests to send JSON payloads instead of
  `application/x-www-form-urlencoded`. All payloads except those for media
  uploads support this, and several newer endpoints (such as methods for
  allowing direct message media attachments) now require them.

- [#40](https://github.com/zendframework/ZendService_Twitter/pull/40) updates
  direct message support to set the character limit to 10k, as documented
  currently for the Twitter API.

- [#34](https://github.com/zendframework/ZendService_Twitter/pull/34) updates
  the `Twitter` class to return a `ZendService\Twitter\Response` instance
  instead of a zend-http response instance. This allows auto-parsing of the
  returned JSON response, as well as access to rate limit information.

### Deprecated

- Nothing.

### Removed

- [#39](https://github.com/zendframework/ZendService_Twitter/pull/39) removes
  support for PHP versions less than 7.1.

### Fixed

- Nothing.
