<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Nadybot\Modules\HELPBOT_MODULE\Playfield;

class WhereisResult extends Whereis {
	public ?Playfield $pf;

	public function toWaypoint(?string $name=null): string {
		$name ??= "{$this->xcoord}x{$this->ycoord} ".
			($this->pf?->short_name ?? "UNKNOWN");
		$coords = "{$this->xcoord} {$this->ycoord} {$this->playfield_id}";
		return "<a href='chatcmd:///waypoint {$coords}'>{$name}</a>";
	}
}
