<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

abstract class UserStateEvent {
	public function __construct(
		public string $sender,
		public int $uid,
		public ?bool $wasOnline=null,
	) {
	}
}
