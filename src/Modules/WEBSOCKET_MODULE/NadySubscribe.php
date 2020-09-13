<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\JSONDataModel;

class NadySubscribe extends JSONDataModel {
	/** @var string[] */
	public array $events = [];
}
