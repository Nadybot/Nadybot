<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

/** Triggered when someone's ban is lifted */
#[Event(mask: 'sync(ban-delete)')]
final class SyncBanDeleteEvent extends SyncEvent {
	/**
	 * @param int    $uid         uid of the banned person
	 * @param string $name        name of the banned person
	 * @param string $unbanned_by Name of the person who banned $uid
	 */
	public function __construct(
		public int $uid,
		public string $name,
		public string $unbanned_by,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct(
			sourceBot: $sourceBot,
			sourceDimension: $sourceDimension,
			forceSync: $forceSync,
		);
	}

	/**
	 * @return array<string,int|string|null>
	 *
	 * @phpstan-return array{"uid":int, "name":string, "unbanned_by":?string}
	 */
	public function toData(): array {
		return [
			'uid' => $this->uid,
			'name' => $this->name,
			'unbanned_by' => $this->unbanned_by,
		];
	}
}
