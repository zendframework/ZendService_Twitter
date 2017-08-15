<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendServiceTest\Twitter;

use PHPUnit\Framework\TestCase;
use ZendService\Twitter\Video;

class VideoTest extends TestCase
{
    public function testCanBeInstantiatedWithNoMediaTypeAndUsesSaneDefaults()
    {
        $video = new Video(__FILE__);
        $this->assertAttributeEquals(__FILE__, 'imageFilename', $video);
        $this->assertAttributeEquals('video/mp4', 'mediaType', $video);
    }

    public function testCanBeInstantiatedWithFilenameAndMediaType()
    {
        $video = new Video(__FILE__, 'text/plain');
        $this->assertAttributeEquals(__FILE__, 'imageFilename', $video);
        $this->assertAttributeEquals('text/plain', 'mediaType', $video);
    }
}
