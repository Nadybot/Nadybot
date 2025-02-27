<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use Nadybot\Core\{
	DBSchema\CmdPermSetMapping,
	Routing\Character,
	Types\CommandReply,
};

/** The command context is a collection of properties that lead to a command's execution */
class CmdContext implements CommandReply {
	/**
	 * This keeps track of the duration of executed commands
	 *
	 * A list of the command's start time, and its execution duration  in ms
	 *
	 * @var list<array<int,int|float>>
	 *
	 * @psalm-var list<array{0:int,1:float}> $cmdStat
	 */
	public static array $cmdStats = [];

	public Character $char;

	private float $started;

	/**
	 * @param string                 $charName          Name of the character executing a command
	 * @param null|CommandReply      $sendto            An object where all messages should be sent to
	 * @param null|int               $charId            The UID of the character, or `null`
	 *                                                  if unknown / not applicable
	 * @param string                 $message           The full message that makes up the command.
	 *                                                  This includes parameter as well
	 * @param null|string            $permissionSet     Name of the permission set, or
	 *                                                  `null` if not given
	 * @param null|string            $source            Where did the command originate from?
	 * @param array<array-key,mixed> $args              The arguments as an associative, and
	 *                                                  list array
	 * @param bool                   $forceSync         Are we executed in a force sync environment?
	 * @param bool                   $isDM              Is the command coming from a direct message?
	 * @param null|CmdPermSetMapping $mapping           The permission set mapping used for
	 *                                                  executing this command.
	 *                                                  This keeps track or the `<symbol>` to use
	 * @param list<Closure>          $shutdownFunctions A list of functions to
	 *                                                  run after the command execution is completed
	 */
	public function __construct(
		string $charName,
		public ?CommandReply $sendto=null,
		?int $charId=null,
		public string $message='',
		public ?string $permissionSet=null,
		public ?string $source=null,
		public array $args=[],
		public bool $forceSync=false,
		public bool $isDM=false,
		public ?CmdPermSetMapping $mapping=null,
		public array $shutdownFunctions=[],
	) {
		$this->char = new Character($charName, $charId);
		$this->started = microtime(true);
	}

	/** On destruction, execute all shutdown functions */
	public function __destruct() {
		static::$cmdStats = array_values(
			array_filter(static::$cmdStats, static function (array $stats): bool {
				return time() - $stats[0] <= 600;
			})
		);
		static::$cmdStats []= [time(), (microtime(true)-$this->started) * 1_000];
		foreach ($this->shutdownFunctions as $callback) {
			$callback();
		}
	}

	/** Set if this command is coming from a direct message */
	public function setIsDM(bool $isDM=true): self {
		$this->isDM = $isDM;
		return $this;
	}

	/**
	 * Send a reply back to the source of this command
	 *
	 * @param string|list<string> $msg
	 */
	public function reply(string|array $msg): void {
		if (isset($this->mapping)) {
			/** @psalm-suppress PossiblyInvalidArgument */
			$msg = str_replace('<symbol>', $this->mapping->symbol, $msg);
		}
		$this->sendto?->reply($msg);
	}

	/** Check if we received this from a direct message of any form */
	public function isDM(): bool {
		return $this->isDM;
	}

	/** Add a function to execute when the object is destroyed */
	public function registerShutdownFunction(Closure $callback): void {
		$this->shutdownFunctions []= $callback;
	}

	/** Get the base command for this context */
	public function getCommand(): string {
		return strtolower(explode(' ', $this->message)[0]);
	}
}
