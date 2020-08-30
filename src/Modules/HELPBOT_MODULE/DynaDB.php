<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\DBRow;

class DynaDB extends DBRow {
	public int $playfield_id;
	public ?string $label;
	public ?string $mob;
	public ?int $minQl;
	public ?int $maxQl;
	public ?int $cX;
	public ?int $cY;
}
