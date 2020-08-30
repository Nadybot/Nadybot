<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\JSONDataModel;

class PlayerHistory extends JSONDataModel {
	public string $name;
	/** @var \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerHistoryData[] */
	public array $data = [];
}
