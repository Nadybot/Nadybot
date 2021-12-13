<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Nadybot\Core\Attributes as NCA;

class HighwayPublic extends Highway {
	public function __construct(array $rooms) {
		$this->rooms = $rooms;
	}
}
