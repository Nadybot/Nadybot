<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Note extends DBRow {
	public const REMIND_NONE = 0;
	public const REMIND_SELF = 1;
	public const REMIND_ALL = 2;

	public int $dt;

	public function __construct(
		public string $owner,
		public string $added_by,
		public string $note,
		?int $dt=null,
		public int $reminder=self::REMIND_NONE,
		#[NCA\DB\AutoInc] public ?int $id=null
	) {
		$this->dt = $dt ?? time();
	}
}
