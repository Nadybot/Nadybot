<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable, Types\SettingMode};

#[NCA\DB\Table(name: 'settings')]
class Setting extends DBTable {
	public function __construct(
		#[NCA\DB\PK] public string $name,
		public SettingMode $mode,
		public ?string $module=null,
		public ?string $type=null,
		public ?string $description=null,
		public ?string $source=null,
		public ?string $admin=null,
		public ?string $help=null,
		public ?string $value='0',
		public ?string $options='0',
		public ?string $intoptions='0',
		public ?int $verify=0,
		public ?bool $confidential=false,
	) {
	}
}
