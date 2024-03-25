<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Setting extends DBRow {
	public function __construct(
		public string $name,
		public string $mode,
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
	) {
	}
}
