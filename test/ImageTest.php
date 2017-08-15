<?php
/**
 * @see       https://github.com/zendframework/ZendService_Twitter for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/ZendService_Twitter/blob/master/LICENSE.md New BSD License
 */

namespace ZendServiceTest\Twitter;

use PHPUnit\Framework\TestCase;
use ZendService\Twitter\Image;

class ImageTest extends TestCase
{
    public function testCanBeInstantiatedWithNoMediaTypeAndUsesSaneDefaults()
    {
        $image = new Image(__FILE__);
        $this->assertAttributeEquals(__FILE__, 'imageFilename', $image);
        $this->assertAttributeEquals('image/jpeg', 'mediaType', $image);
    }

    public function testCanBeInstantiatedWithFilenameAndMediaType()
    {
        $image = new Image(__FILE__, 'text/plain');
        $this->assertAttributeEquals(__FILE__, 'imageFilename', $image);
        $this->assertAttributeEquals('text/plain', 'mediaType', $image);
    }
}
