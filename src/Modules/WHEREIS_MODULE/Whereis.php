<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable, Text};
use Nadybot\Core\Types\Playfield;

#[NCA\DB\Table(name: 'whereis', shared: NCA\DB\Shared::Yes)]
class Whereis extends DBTable {
	public function __construct(
		#[NCA\DB\PK] public int $id,
		public string $name,
		public string $answer,
		public ?string $keywords,
		#[NCA\DB\ColName('playfield_id')] public Playfield $playfield,
		public int $xcoord,
		public int $ycoord,
	) {
	}

	public function toWaypoint(?string $name=null): string {
		if (!isset($name)) {
			$name = "{$this->xcoord}x{$this->ycoord}";
			if ($this->playfield !== Playfield::Unknown) {
				$name .= ' ' . $this->playfield->short();
			}
		}
		$coords = "{$this->xcoord} {$this->ycoord} {$this->playfield->value}";
		return Text::makeChatcmd($name, "/waypoint {$coords}");
	}
}
