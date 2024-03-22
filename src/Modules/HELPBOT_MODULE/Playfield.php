<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\DBRow;

class Playfield extends DBRow {
	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		'pf-id' => 551,
		'pf-long' => 'Wailing Wastes',
		'pf-short' => 'WW',
	];

	public int $id;
	public string $long_name;
	public string $short_name;

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		return [
			'pf-id' => $this->id,
			'pf-long' => $this->long_name,
			'pf-short' => $this->short_name,
		];
	}
}
