<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\DBSchema\Alt;
use Nadybot\Core\DBSchema\Player;

class AltPlayer extends Alt {
	public ?Player $player = null;
}
