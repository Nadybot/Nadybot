<?php declare(strict_types=1);

namespace Nadybot\User\Modules\SPAWNTIME_MODULE;

use Nadybot\Modules\HELPBOT_MODULE\Playfield;
use Nadybot\Modules\WHEREIS_MODULE\WhereisTrait;

class WhereisCoordinates extends Playfield {
	use WhereisTrait;

	public function __construct(Spawntime $spawn) {
		$this->answer = $spawn->answer;
		$this->keywords = $spawn->keywords;
		if (isset($spawn->long_name)) {
			$this->long_name = $spawn->long_name;
		}
		$this->name = $spawn->name;
		$this->playfield_id = (int)$spawn->playfield_id;
		$this->short_name = $spawn->short_name;
		$this->xcoord = (int)$spawn->xcoord;
		$this->ycoord = (int)$spawn->ycoord;
	}
}
