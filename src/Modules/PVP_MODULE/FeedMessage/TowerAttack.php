<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\StringableTrait;

class TowerAttack {
	use StringableTrait;

	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		// ...Attacker::EXAMPLE_TOKENS,
		"att-org-name" => "Team Rainbow",
		"c-att-org-name" => "<clan>Team Rainbow<end>",
		"att-org" => "Team Rainbow",
		"c-att-org" => "<clan>Team Rainbow<end>",
		"att-org-faction" => 'Clan',
		"c-att-org-faction" => '<clan>Clan<end>',

		'att-name' => 'Nady',
		'c-att-name' => '<highlight>Nady<end>',
		'att-level' => 220,
		'c-att-level' => '<highlight>220<end>',
		'att-ai-level' => 30,
		'c-att-ai-level' => '<green>30<end>',
		'att-prof' => 'Bureaucrat',
		'c-att-prof' => '<highlight>Bureaucrat<end>',
		'att-profession' => 'Bureaucrat',
		'c-att-profession' => '<highlight>Bureaucrat<end>',
		'att-org-rank' => 'Advisor',
		'c-att-org-rank' => '<highlight>Advisor<end>',
		'att-gender' => 'Female',
		'c-att-gender' => '<highlight>Female<end>',
		'att-breed' => 'Nano',
		'c-att-breed' => '<highlight>Nano<end>',
		'att-faction' => 'Clan',
		'c-att-faction' => '<clan>Clan<end>',
		// ...DefenderOrg::EXAMPLE_TOKENS,
		"def-org" => "Troet",
		"c-def-org" => "<neutral>Troet<end>",
		"def-faction" => "Neutral",
		"c-def-faction" => "<neutral>Neutral<end>",

		"pf-id" => 551,
		"att-coord-x" => 700,
		"att-coord-y" => 800,
	];

	public bool $isFake = false;

	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public ?int $ql,
		public Attacker $attacker,
		public Coordinates $location,
		public DefenderOrg $defender,
		public int $timestamp,
		public ?int $penalizing_ended,
	) {
		$this->isFake = !isset($attacker->character_id)
			|| (!isset($attacker->org) && !isset($attacker->level));
	}

	public function addLookups(?Player $player): void {
		$this->attacker->ai_level ??= $player?->ai_level;
		$this->attacker->faction ??= $player?->faction;
		$this->attacker->breed ??= $player?->breed;
		$this->attacker->gender ??= $player?->gender;
		$this->attacker->level ??= $player?->level;
		if (isset($this->attacker->org)) {
			$this->attacker->org_rank ??= $player?->guild_rank;
		}
		$this->attacker->profession ??= $player?->profession;
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		$tokens = [
			"pf-id" => $this->playfield_id,
			"att-coord-x" => $this->location->x,
			"att-coord-y" => $this->location->y,
		];
		return array_merge(
			$tokens,
			$this->attacker->getTokens(),
			$this->defender->getTokens(),
		);
	}
}
