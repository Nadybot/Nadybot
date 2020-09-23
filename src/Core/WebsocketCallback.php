<?php declare(strict_types=1);

namespace Nadybot\Core;

class WebsocketCallback {
	public WebsocketServer $websocket;
	public string $eventName;
	public ?string $data = null;
	public ?int $code = null;
}
