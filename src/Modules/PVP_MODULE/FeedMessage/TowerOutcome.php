<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use function Safe\date;
use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\{StringableTrait, Util};

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
		public int $playfield_id,
		public int $site_id,
		public int $timestamp,
		#[MapFrom('attacking_faction')] public ?string $attacker_faction,
		#[MapFrom('attacking_org')] public ?string $attacker_org,
		public string $losing_faction,
		public string $losing_org,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		return [
			'pf-id' => $this->playfield_id,
			'site-id' => $this->site_id,
			'timestamp' => date(Util::DATETIME, $this->timestamp),
			'winning-faction' => $this->attacker_faction,
			'c-winning-faction' => isset($this->attacker_faction)
				? '<' . strtolower($this->attacker_faction) . '>'.
				$this->attacker_faction . '<end>'
				: null,
			'winning-org' => $this->attacker_org,
			'c-winning-org' => isset($this->attacker_faction, $this->attacker_org)
				? '<' . strtolower($this->attacker_faction) . '>'.
				$this->attacker_org . '<end>'
				: null,
			'losing-faction' => $this->losing_org,
			'c-losing-faction' => '<' . strtolower($this->losing_faction) . '>'.
				$this->losing_faction . '<end>',
			'losing-org' => $this->losing_faction,
			'c-losing-org' => '<' . strtolower($this->losing_faction) . '>'.
				$this->losing_org . '<end>',
		];
	}
}
