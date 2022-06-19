<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\DBSchema\{Alt, Player};

class AltPlayer extends Alt {
	public ?Player $player = null;
}
