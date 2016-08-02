<?php

namespace ZendService\Twitter;

use Zend\Http\Client as Client;
use ZendService\Twitter\Response as Response;
class Video extends Media
{

	public function __construct($image_url = null, $media_type = 'video/mp4')
	{
		parent::__construct($image_url, $media_type);
	}

}
