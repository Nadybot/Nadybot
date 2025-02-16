<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Attributes\Hydrator\{StrFuncIn, StrFuncOut};
use Nadybot\Core\Events\SyncEvent;
use Nadybot\Core\Types\Faction;
use Nadybot\Core\Util;

#[Event(mask: 'sync(gaubuff)')]
class SyncGaubuffEvent extends SyncEvent {
	/**
	 * @param int     $expires UNIX timestamp when the buff expires
	 * @param Faction $faction For which faction: neutral, clan or omni
	 * @param string  $sender  Name of the person reporting the gauntlet buff
	 */
	public function __construct(
		public int $expires,
		#[
			StrFuncIn('ucfirst'),
			StrFuncOut([Util::class, 'enumToValue'], 'strtolower')
		] public Faction $faction,
		public string $sender,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
