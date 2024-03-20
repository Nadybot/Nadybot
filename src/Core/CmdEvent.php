<?php declare(strict_types=1);

namespace Nadybot\Core;

abstract class CmdEvent extends Event {
	public const EVENT_MASK = "command(*)";

	public string $sender;
	public string $channel;
	public string $cmd;
	public ?CommandHandler $cmdHandler;
}
