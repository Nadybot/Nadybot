<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'links', shared: NCA\DB\Shared::Yes)]
class Link extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;
	public int $dt;

	public function __construct(
		public string $name,
		public string $website,
		public string $comments,
		?int $dt=null,
		?UuidInterface $id=null,
	) {
		$this->dt = $dt ?? time();
		$time = null;
		if (isset($dt) && !isset($id)) {
			$time = (new DateTimeImmutable())->setTimestamp($dt);
		}
		$this->id = $id ?? Uuid::uuid7($time);
	}
}
