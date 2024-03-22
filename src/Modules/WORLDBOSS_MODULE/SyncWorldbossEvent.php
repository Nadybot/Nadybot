<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncWorldbossEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(worldboss)';

	/**
	 * @param int    $vulnerable UNIX timestamp when the world boss will be vulnerable
	 * @param string $boss       For which worldboss: tara, reaper, loren, gauntlet
	 * @param string $sender     Name of the person reporting this event
	 */
	public function __construct(
		public int $vulnerable,
		public string $boss,
		public string $sender,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
