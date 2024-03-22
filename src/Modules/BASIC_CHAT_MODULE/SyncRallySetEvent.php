<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\SyncEvent;

class SyncRallySetEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(rally-set)';

	/**
	 * @param string $owner Character who created the rally
	 * @param string $name  Name of this rally point
	 * @param int    $x     X coordinate
	 * @param int    $y     Y coordinate
	 * @param int    $pf    Numeric playfield Id
	 */
	public function __construct(
		public string $owner,
		public string $name,
		public int $x,
		public int $y,
		public int $pf,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
