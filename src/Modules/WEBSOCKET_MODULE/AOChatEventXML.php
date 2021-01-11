<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\AOChatEvent;
use Nadybot\Modules\WEBSERVER_MODULE\AOMsg;

class AOChatEventXML extends AOChatEvent {
	public AOMsg $structMessage;

	public static function fromAOChatEvent(AOChatEvent $event): self {
		$result = new static();
		foreach ($event as $key => $value) {
			$result->{$key} = $value;
		}
		$result->type = "chat(" . str_replace("send", "", $result->type) . ")";
		$result->structMessage = AOMsg::fromMsg($event->message);
		return $result;
	}
}
