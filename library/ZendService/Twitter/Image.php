<?php

namespace ZendService\Twitter;
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Service
 */

use Zend\Http\Client as Client;
use ZendService\Twitter\Response as Response;

/**
 * Twitter Image Uploader
 *
 * @category   Zend
 * @package    Zend_Service
 * @subpackage Twitter
 * @author Cal Evans <cal@calevans.com>
 */
class Image extends Media
{

	public function __construct($image_url = null, $media_type = 'image/jpeg')
	{
		parent::__construct($image_url, $media_type);
	}

}
