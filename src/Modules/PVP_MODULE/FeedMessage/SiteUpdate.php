<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use function Safe\date;

use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\{Faction, Playfield, StringableTrait, Util};
use Nadybot\Modules\PVP_MODULE\Attributes\CastToTiming;

class SiteUpdate {
	use StringableTrait;

	public const TIMING_DYNAMIC = 0;
	public const TIMING_US = 1;
	public const TIMING_EU = 2;

	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		'site-pf-id' => 660,
		'site-id' => 6,
		'site-nr' => 6,
		'site-number' => 6,
		'site-enabled' => 1,
		'site-min-ql' => 20,
		'site-max-ql' => 30,
		'site-name' => 'Charred Groove',
		'site-num-conductors' => 0,
		'site-num-turrets' => 5,
		'site-num-cts' => 1,
		'site-gas' => '75%',
		'c-site-gas' => '<red>75%<end>',
		'site-faction' => 'Neutral',
		'c-site-faction' => '<neutral>Neutral<clan>',
		'site-org-id' => 1,
		'site-org-name' => 'Troet',
		'c-site-org-name' => '<neutral>Troet<end>',
		'site-plant-time' => '13-Jan-2023 17:07 UTC',
		'site-ct-ql' => 25,
	];

	public function __construct(
		#[MapFrom('playfield_id')] public Playfield $playfield,
		public int $site_id,
		public bool $enabled,
		public int $min_ql,
		public int $max_ql,
		public string $name,
		#[CastToTiming] public int $timing,
		public Coordinates $center,
		public int $num_conductors=0,
		public ?Coordinates $ct_pos=null,
		public int $num_turrets=0,
		public ?int $gas=null,
		public ?Faction $org_faction=null,
		public ?int $org_id=null,
		public ?string $org_name=null,
		public ?int $plant_time=null,
		public ?int $ql=null,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		$tokens = [
			'site-pf-id' => $this->playfield->value,
			'site-id' => $this->site_id,
			'site-nr' => $this->site_id,
			'site-number' => $this->site_id,
			'site-enabled' => (int)$this->enabled,
			'site-min-ql' => $this->min_ql,
			'site-max-ql' => $this->max_ql,
			'site-name' => $this->name,
			'site-num-conductors' => $this->num_conductors,
			'site-num-turrets' => $this->num_turrets,
			'site-num-cts' => isset($this->ql) ? '1' : '0',
			'site-gas' => $this->gas,
			'site-faction' => $this->org_faction?->value,
			'site-org-id' => $this->org_id,
			'site-org-name' => $this->org_name,
			'site-plant-time' => isset($this->plant_time)
				? date(Util::DATETIME, $this->plant_time)
				: null,
			'site-ct-ql' => $this->ql,
		];

		if (isset($this->org_faction, $this->org_name)) {
			$tokens['c-site-faction'] = $this->org_faction->inColor();
			$tokens['c-site-org-name'] = $this->org_faction->inColor($this->org_name);
		}
		if (isset($this->gas)) {
			$tokens['site-gas'] = "{$this->gas}%";
			$gasColor = ($this->gas === 75) ? 'red' : 'green';
			$tokens['c-site-gas'] = "<{$gasColor}>{$this->gas}%<end>";
		}

		return $tokens;
	}
}
