<?php

namespace Budabot\Core;

use stdClass;

class Event extends stdClass {
	/** @var string */
	public $sender;

	/** @var string */
	public $type;

	/** @var string */
	public $packet;

	/** @var string */
	public $channel;

	/** @var string */
	public $message;
}
