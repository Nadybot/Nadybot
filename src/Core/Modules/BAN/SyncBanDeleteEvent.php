<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\SyncEvent;

class SyncBanDeleteEvent extends SyncEvent {
	public string $type = "sync(ban-delete)";

	/** uid of the banned person */
	public int $uid;

	/** name of the banned person */
	public string $name;

	/** Name of the person who banned $uid */
	public string $unbanned_by;

	/**
	 * @return array<string,int|string|null>
	 *
	 * @phpstan-return array{"uid":int, "name":string, "unbanned_by":?string}
	 */
	public function toData(): array {
		return [
			"uid" => $this->uid,
			"name" => $this->name,
			"unbanned_by" => $this->unbanned_by,
		];
	}
}
