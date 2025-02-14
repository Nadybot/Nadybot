<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use Nadybot\Core\{
	DBSchema\CmdPermSetMapping,
	Routing\Character,
	Types\CommandReply,
};

class CmdContext implements CommandReply {
	/**
	 * @var array<array<int|float>>
	 *
	 * @psalm-var array{int,float}[] $cmdStat
	 */
	public static array $cmdStats = [];

	public Character $char;

	private float $started;

	/**
	 * @param array<array-key,mixed> $args
	 * @param list<Closure>          $shutdownFunctions
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

	public function setIsDM(bool $isDM=true): self {
		$this->isDM = $isDM;
		return $this;
	}

	/** @param string|list<string> $msg */
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

	public function registerShutdownFunction(Closure $callback): void {
		$this->shutdownFunctions []= $callback;
	}

	public function getCommand(): string {
		return strtolower(explode(' ', $this->message)[0]);
	}
}
