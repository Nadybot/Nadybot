<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\{StringableTrait, Types\Faction};

class DefenderOrg {
	use StringableTrait;

	/** @var array<string,null|int|string> */
	public const EXAMPLE_TOKENS = [
		'def-org' => 'Troet',
		'c-def-org' => '<neutral>Troet<end>',
		'def-faction' => 'Neutral',
		'c-def-faction' => '<neutral>Neutral<end>',
	];

	public function __construct(
		public string $name,
		public Faction $faction,
	) {
	}

	/** @return array<string,null|string|int> */
	public function getTokens(): array {
		return [
				'def-org' => $this->name,
				'c-def-org' => $this->faction->inColor($this->name),
				'def-faction' => $this->faction->value,
				'c-def-faction' => $this->faction->inColor(),
		];
	}
}
