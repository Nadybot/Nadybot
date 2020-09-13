<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\JSONDataModel;

class WebsocketCommand extends JSONDataModel {
	public const EVENT = "event";
	public const SUBSCRIBE = "subscribe";
	public const REQUEST = "request";
	public const RESPONSE = "response";
	public const ALLOWED_COMMANDS = [
		self::EVENT,
		self::SUBSCRIBE,
		self::REQUEST,
		self::RESPONSE,
	];

	public string $command;
	public $data;
}
