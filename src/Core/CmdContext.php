<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use Nadybot\Core\DBSchema\CmdPermSetMapping;
use Nadybot\Core\Routing\Character;

class CmdContext implements CommandReply {
	/**
	 * @var array<array<int|float>>
	 *
	 * @phpstan-var array{int,float}[]
	 */
	public static array $cmdStats = [];
	public string $message = "";
	public ?string $permissionSet = null;
	public ?string $source = null;
	public Character $char;
	public CommandReply $sendto;

	/** @var mixed[] */
	public array $args = [];
	public bool $forceSync = false;
	public bool $isDM = false;
	public ?CmdPermSetMapping $mapping = null;

	/** @var array<Closure> */
	public array $shutdownFunctions = [];

	private float $started;

	public function __construct(string $charName, ?int $charId=null) {
		$this->char = new Character($charName, $charId);
		$this->started = microtime(true);
	}

	public function __destruct() {
		static::$cmdStats = array_values(
			array_filter(static::$cmdStats, function (array $stats): bool {
				return time() - $stats[0] <= 600;
			})
		);
		static::$cmdStats []= [time(), (microtime(true)-$this->started) * 1000];
		foreach ($this->shutdownFunctions as $callback) {
			$callback();
		}
	}

	public function setIsDM(bool $isDM=true): self {
		$this->isDM = $isDM;
		return $this;
	}

	public function reply($msg): void {
		if (isset($this->mapping)) {
			$msg = str_replace("<symbol>", $this->mapping->symbol, $msg);
		}
		$this->sendto->reply($msg);
	}

	/** Check if we received this from a direct message of any form */
	public function isDM(): bool {
		return $this->isDM;
	}

	public function registerShutdownFunction(Closure $callback): void {
		$this->shutdownFunctions []= $callback;
	}

	public function getCommand(): string {
		return strtolower(explode(" ", $this->message)[0]);
	}
}
