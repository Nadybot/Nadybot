<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Types\DoNotSerializePublicFunctions;
use Nadybot\Core\{Attributes as NCA, Registry, StringableTrait};
use Stringable;

/**
 * This is the abstract base class for all commands
 * that can be synced via the NadyNative protocol
 */
#[NCA\Event(mask: 'sync(*)')]
abstract class SyncEvent implements Stringable, DoNotSerializePublicFunctions {
	use StringableTrait;

	/** Name of the bot that sent the event */
	public string $sourceBot;

	/** Dimension where this event originates */
	public int $sourceDimension;

	/** Is this a forced sync? */
	public bool $forceSync = false;

	/**
	 * @param null|string $sourceBot       Name of the bot that sent the event
	 * @param null|int    $sourceDimension Dimension where this event originates
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

	/** Is this an event our bot triggered? */
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
