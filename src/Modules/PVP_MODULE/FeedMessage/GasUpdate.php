<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\{Playfield, StringableTrait};

class GasUpdate {
	use StringableTrait;

	public function __construct(
		#[MapFrom('playfield_id')] public Playfield $playfield,
		public int $site_id,
		public int $gas,
	) {
	}
}
