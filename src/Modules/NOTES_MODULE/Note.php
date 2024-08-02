<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'notes', shared: NCA\DB\Shared::Yes)]
class Note extends DBTable {
	public const REMIND_NONE = 0;
	public const REMIND_SELF = 1;
	public const REMIND_ALL = 2;

	#[NCA\DB\PK] public UuidInterface $id;
	public int $dt;

	public function __construct(
		public string $owner,
		public string $added_by,
		public string $note,
		?int $dt=null,
		public int $reminder=self::REMIND_NONE,
		?UuidInterface $id=null,
	) {
		$this->dt = $dt ?? time();
		$time = null;
		if (isset($dt) && !isset($id)) {
			$time = ((new DateTimeImmutable())->setTimestamp($dt));
		}
		$this->id = $id ?? Uuid::uuid7($time);
	}
}
