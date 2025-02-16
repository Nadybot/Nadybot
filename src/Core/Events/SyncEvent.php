<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{Attributes as NCA, Registry};

#[NCA\Event(mask: 'sync(*)')]
abstract class SyncEvent {
	public string $sourceBot;
	public int $sourceDimension;
	public bool $forceSync = false;

	/**
	 * @param null|string $sourceBot       Name of the bot that sent the event
	 * @param null|int    $sourceDimension Dimension where this event originaes
	 * @param null|bool   $forceSync       Is this a forced sync?
	 */
	public function __construct(
		?string $sourceBot,
		?int $sourceDimension,
		?bool $forceSync,
	) {
		$config = Registry::getInstance(BotConfig::class);
		$this->sourceBot = $sourceBot ?? $config->main->character;
		$this->sourceDimension = $sourceDimension ?? $config->main->dimension;
		$this->forceSync = $forceSync ?? false;
	}

	public function isLocal(): bool {
		if (!isset($this->sourceBot) || !isset($this->sourceDimension)) {
			return true;
		}

		$config = Registry::getInstance(BotConfig::class);
		$myName = $config->main->character;
		$myDim = $config->main->dimension;
		return $this->sourceBot === $myName
			&& $this->sourceDimension === $myDim;
	}
}
