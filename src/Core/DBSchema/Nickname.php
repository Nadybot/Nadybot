<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Nickname extends DBRow {
	public function __construct(
		public string $main,
		public string $nick,
	) {
	}
}
