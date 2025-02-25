<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

/** Someone joins or leaves a private channel */
abstract class JoinLeaveEvent {
	/**
	 * @param string $sender  The name of the person joning/leaving
	 * @param string $channel The name of the channel via which the message was sent
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public ?string $worker=null,
	) {
	}
}
