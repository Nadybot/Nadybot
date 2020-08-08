<?php declare(strict_types=1);

namespace Nadybot\Core;

class Event {
	public string $sender;

	public string $type;

	public AOChatPacket $packet;

	public string $channel;

	public string $message;
}
