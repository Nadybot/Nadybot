<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class WebsocketCommand {
	public const string EVENT = 'event';
	public const string SUBSCRIBE = 'subscribe';
	public const string REQUEST = 'request';
	public const string RESPONSE = 'response';

	/** @var string[] */
	public const array ALLOWED_COMMANDS = [
		self::EVENT,
		self::SUBSCRIBE,
		self::REQUEST,
		self::RESPONSE,
	];

	public function __construct(
		public string $command,
		public mixed $data,
	) {
	}
}
