<?php declare(strict_types=1);

namespace Nadybot\Core;

class WebsocketEvent {
	public WebsocketBase $websocket;
	public string $eventName;
	public ?string $data = null;
	public ?int $code = null;
}
