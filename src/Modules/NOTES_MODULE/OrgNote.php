<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class OrgNote extends DBRow {
	public int $added_on;

	public function __construct(
		public string $uuid,
		public string $added_by,
		public string $note,
		?int $added_on=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		$this->added_on = $added_on ?? time();
	}
}
