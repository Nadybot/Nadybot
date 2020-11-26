<?php declare(strict_types=1);

namespace Nadybot\Core;

class CmdEvent extends Event {
	/** Either the name of the sender or the numeric UID (eg. city raid accouncements) */
	public $sender;

	/** Where was the command received? */
	public string $channel;

	/** Which command was received? */
	public string $cmd;

	/** The command handler that will be/was used to execute the command */
	public ?CommandHandler $cmdHandler;
}
