<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(ban)')]
final class SyncBanEvent extends SyncEvent {
	/**
	 * @param int     $uid          uid of the banned person
	 * @param string  $name         name of the banned person
	 * @param string  $banned_by    Name of the person who banned $uid
	 * @param ?string $reason       Reason why $uid was banned
	 * @param ?int    $banned_until Unix timestamp when the ban ends, or null/0 if never
	 */
	public function __construct(
		public int $uid,
		public string $name,
		public string $banned_by,
		public ?string $reason=null,
		public ?int $banned_until=null,
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
	 * @phpstan-return array{"uid":int, "name":string, "banned_by":?string, "banned_until":?int, "reason":?string}
	 */
	public function toData(): array {
		return [
			'uid' => $this->uid,
			'name' => $this->name,
			'banned_by' => $this->banned_by,
			'banned_until' => $this->banned_until,
			'reason' => $this->reason,
		];
	}
}
