<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'playfields', shared: Shared::Yes)]
class Playfield extends DBTable {
	/** @var array<string,null|string|int> */
	public const EXAMPLE_TOKENS = [
		'pf-id' => 551,
		'pf-long' => 'Wailing Wastes',
		'pf-short' => 'WW',
	];

	public function __construct(
		#[PK] public int $id,
		public string $long_name,
		public string $short_name,
	) {
	}

	/** @return array<string,null|string|int> */
	public function getTokens(): array {
		return [
			'pf-id' => $this->id,
			'pf-long' => $this->long_name,
			'pf-short' => $this->short_name,
		];
	}
}
