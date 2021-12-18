<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

class Whereis {
	public int $id;
	public string $name;
	public string $answer;
	public ?string $keywords;
	public int $playfield_id;
	public int $xcoord;
	public int $ycoord;
}
