<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\SyncEvent;

class SyncBanEvent extends SyncEvent {
	public const EVENT_MASK = "sync(ban)";

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
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}

	/**
	 * @return array<string,int|string|null>
	 *
	 * @phpstan-return array{"uid":int, "name":string, "banned_by":?string, "banned_until":?int, "reason":?string}
	 */
	public function toData(): array {
		return [
			"uid" => $this->uid,
			"name" => $this->name,
			"banned_by" => $this->banned_by,
			"banned_until" => $this->banned_until,
			"reason" => $this->reason,
		];
	}
}
