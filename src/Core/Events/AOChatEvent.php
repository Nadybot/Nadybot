<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

/**
 * This abstract class is used for events where someone sends a message on AO
 */
abstract class AOChatEvent {
	/**
	 * @param string  $channel The name of the channel via which the message was sent
	 * @param string  $message The message itself
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $channel,
		public string $message,
		public ?string $worker=null,
	) {
	}
}
