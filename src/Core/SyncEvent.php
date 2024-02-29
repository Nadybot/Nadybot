<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Config\BotConfig;

class SyncEvent extends Event {
	public string $sourceBot;
	public int $sourceDimension;
	public bool $forceSync = false;

	final public function __construct() {
	}

	public static function fromSyncEvent(SyncEvent $event): self {
		$obj = new static();
		foreach (get_object_vars($event) as $key => $value) {
			$obj->{$key} = $value;
		}
		return $obj;
	}

	public function isLocal(): bool {
		if (!isset($this->sourceBot) || !isset($this->sourceDimension)) {
			return true;
		}

		/** @var BotConfig */
		$config = Registry::getInstance(BotConfig::class);
		$myName = $config->name;
		$myDim = $config->dimension;
		return $this->sourceBot === $myName
			&& $this->sourceDimension === $myDim;
	}
}
