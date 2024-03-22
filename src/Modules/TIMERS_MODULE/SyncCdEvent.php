<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncCdEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(cd)';

	/**
	 * @param string $owner   Character who started the countdown
	 * @param string $message Message to display at the end of the countdown
	 */
	public function __construct(
		public string $owner,
		public string $message,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
