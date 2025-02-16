<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(rally-set)')]
class SyncRallySetEvent extends SyncEvent {
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
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
