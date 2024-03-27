<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use function Safe\date;
use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\{Faction, Playfield, StringableTrait, Util};

class TowerOutcome {
	use StringableTrait;

	/** @var array<string,int|string|null> */
	public const EXAMPLE_TOKENS = [
			'pf-id' => 551,
			'site-id' => 6,
			'timestamp' => '11-Mar-2023 20:12 UTC',
			'winning-faction' => 'Neutral',
			'c-winning-faction' => '<neutral>Neutral<end>',
			'winning-org' => 'Troet',
			'c-winning-org' => '<neutral>Troet<end>',
			'losing-faction' => 'Clan',
			'c-losing-faction' => '<clan>Clan<end>',
			'losing-org' => 'Team Rainbow',
			'c-losing-org' => '<clan>Team Rainbow<end>',
		];

	/** @var array<string,int|string|null> */
	public const EXAMPLE_ABANDON_TOKENS = [
			'pf-id' => 551,
			'site-id' => 6,
			'timestamp' => '11-Mar-2023 20:12 UTC',
			'winning-faction' => null,
			'c-winning-faction' => null,
			'winning-org' => null,
			'c-winning-org' => null,
			'losing-faction' => 'Clan',
			'c-losing-faction' => '<clan>Clan<end>',
			'losing-org' => 'Team Rainbow',
			'c-losing-org' => '<clan>Team Rainbow<end>',
		];

	public function __construct(
		#[MapFrom('playfield_id')] public Playfield $playfield,
		public int $site_id,
		public int $timestamp,
		#[MapFrom('attacking_faction')] public ?Faction $attacker_faction,
		#[MapFrom('attacking_org')] public ?string $attacker_org,
		public Faction $losing_faction,
		public string $losing_org,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		return [
			'pf-id' => $this->playfield->value,
			'site-id' => $this->site_id,
			'timestamp' => date(Util::DATETIME, $this->timestamp),
			'winning-faction' => $this->attacker_faction?->name,
			'c-winning-faction' => $this->attacker_faction?->inColor(),
			'winning-org' => $this->attacker_org,
			'c-winning-org' => isset($this->attacker_faction, $this->attacker_org)
				? $this->attacker_faction->inColor($this->attacker_org)
				: null,
			'losing-faction' => $this->losing_faction->name,
			'c-losing-faction' => $this->losing_faction->inColor(),
			'losing-org' => $this->losing_org,
			'c-losing-org' => $this->losing_faction->inColor($this->losing_org),
		];
	}
}
