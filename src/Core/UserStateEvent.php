<?php declare(strict_types=1);

namespace Nadybot\Core;

abstract class UserStateEvent extends Event {
	public string $sender;
	public int $uid;
	public ?bool $wasOnline;
}
