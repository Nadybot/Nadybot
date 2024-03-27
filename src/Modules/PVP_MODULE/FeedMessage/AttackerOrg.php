<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\{Faction, StringableTrait};

class AttackerOrg {
	use StringableTrait;

	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		'att-org-name' => 'Team Rainbow',
		'c-att-org-name' => '<clan>Team Rainbow<end>',
		'att-org' => 'Team Rainbow',
		'c-att-org' => '<clan>Team Rainbow<end>',
		'att-org-faction' => 'Clan',
		'c-att-org-faction' => '<clan>Clan<end>',
	];

	public function __construct(
		public string $name,
		public Faction $faction,
		public ?int $id,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		return [
			'att-org-name' => $this->name,
			'c-att-org-name' => $this->faction->inColor($this->name),
			'att-org' => $this->name,
			'c-att-org' => $this->faction->inColor($this->name),
			'att-org-faction' => $this->faction->value,
			'c-att-org-faction' => $this->faction->inColor(),
		];
	}
}
