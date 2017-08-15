<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendService\Twitter;

/**
 * Twitter Video Uploader
 *
 * @author Cal Evans <cal@calevans.com>
 */
class Video extends Media
{
    public function __construct(
        string $imageUrl,
        string $mediaType = 'video/mp4',
        bool $forDirectMessage = false,
        bool $shared = false
    ) {
        parent::__construct($imageUrl, $mediaType, $forDirectMessage, $shared);
    }
}
