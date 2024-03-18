<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Event\AOChatEvent;

class AOWebChatEvent extends AOChatEvent {
	public const EVENT_MASK = "chat(web)";

	/**
	 * @param string           $sender  The name of the sender of the message
	 * @param string           $channel The name of the channel via which the message was sent
	 * @param string           $message The message itself
	 * @param ?string          $worker  If set, this is the id of the worker via which the message was received
	 * @param null|WebSource[] $path
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $message,
		public string $color,
		public ?array $path=null,
		public ?string $worker=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
