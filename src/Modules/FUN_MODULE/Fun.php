<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'fun', shared: NCA\DB\Shared::Yes)]
class Fun extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $type,
		public string $content,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
