<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

trait WhereisTrait {
	public int $id;
	public string $name;
	public string $answer;
	public ?string $keywords;
	public int $playfield_id;
	public int $xcoord;
	public int $ycoord;
}
