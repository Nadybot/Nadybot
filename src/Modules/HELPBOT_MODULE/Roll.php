<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'roll', shared: NCA\DB\Shared::Yes)]
class Roll extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public ?string $name,
		public ?string $options,
		public ?string $result,
		public ?int $time=null,
		?UuidInterface $id=null,
	) {
		$dt = null;
		if (isset($time) && !isset($id)) {
			$dt = (new DateTimeImmutable())->setTimestamp($time);
		}
		$this->id = $id ?? Uuid::uuid7($dt);
		$this->time ??= time();
	}
}
