<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Config\BotConfig;

abstract class SyncEvent extends Event {
	public const EVENT_MASK = "sync(*)";

	public string $sourceBot;
	public int $sourceDimension;
	public bool $forceSync = false;

	public function __construct(
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		/** @var BotConfig */
		$config = Registry::getInstance(BotConfig::class);
		$this->sourceBot = $sourceBot ?? $config->main->character;
		$this->sourceDimension = $sourceDimension ?? $config->main->dimension;
		$this->forceSync = $forceSync ?? false;
	}

	public function isLocal(): bool {
		if (!isset($this->sourceBot) || !isset($this->sourceDimension)) {
			return true;
		}

		/** @var BotConfig */
		$config = Registry::getInstance(BotConfig::class);
		$myName = $config->main->character;
		$myDim = $config->main->dimension;
		return $this->sourceBot === $myName
			&& $this->sourceDimension === $myDim;
	}
}
