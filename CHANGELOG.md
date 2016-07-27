# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 2.2.0 - TBD

### Added

- [#29](https://github.com/zendframework/ZendService_Twitter/pull/29) adds
  support for:
  - retrieving a list of follower identifiers (`/followers/ids` API)
  - retrieving the lists to which the user belongs (`/lists/memberships` API)
  - retrieving the friends of the user (`/friendships/lookup` API)
  - retrieving full user data on a list of users (`/users/lookup` API)
  - retrieving the identifiers for friends of the user (`/friends/ids` API)
- [#31](https://github.com/zendframework/ZendService_Twitter/pull/31) adds
  support for PHP 7.

### Deprecated

- Nothing.

### Removed

- [#31](https://github.com/zendframework/ZendService_Twitter/pull/31) removes
  support for PHP versions less than 5.6.

### Fixed

- Nothing.
