<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\DBRow;

class NewsConfirmed extends DBRow {
	/** The internal ID of this news entry */
	public int $id;

	public string $player;
	public int $time;
}
