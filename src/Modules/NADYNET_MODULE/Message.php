<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

class Message {
	public function __construct(
		public int $dimension,
		public int $bot_uid,
		public string $bot_name,
		public ?int $sender_uid,
		public string $sender_name,
		public ?string $main,
		public ?string $nick,
		public int $sent,
		public string $channel,
		public string $message,
	) {
	}
}
