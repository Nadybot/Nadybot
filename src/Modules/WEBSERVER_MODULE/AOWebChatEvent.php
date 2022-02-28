<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\AOChatEvent;

class AOWebChatEvent extends AOChatEvent {
	/** @var null|WebSource[] */
	public ?array $path;

	public string $color;
}
