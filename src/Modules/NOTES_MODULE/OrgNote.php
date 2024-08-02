<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'org_notes', shared: NCA\DB\Shared::Yes)]
class OrgNote extends DBTable {
	public int $added_on;
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $added_by,
		public string $note,
		?int $added_on=null,
		?UuidInterface $id=null,
	) {
		$this->added_on = $added_on ?? time();
		$time = null;
		if (isset($added_on) && !isset($id)) {
			$time = ((new DateTimeImmutable())->setTimestamp($added_on));
		}
		$this->id = $id ?? Uuid::uuid7($time);
	}
}
