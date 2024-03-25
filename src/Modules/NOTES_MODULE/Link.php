<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Link extends DBRow {
	public int $dt;

	public function __construct(
		public string $name,
		public string $website,
		public string $comments,
		?int $dt=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		$this->dt = $dt ?? time();
	}
}
