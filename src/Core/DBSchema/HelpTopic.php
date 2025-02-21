<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\{MapRead, MapWrite};
use Nadybot\Core\DBRow;
use Nadybot\Core\Types\AccessLevel;

class HelpTopic extends DBRow {
	/** @param list<AccessLevel> $admin_list */
	public function __construct(
		#[
			MapRead([self::class, 'dbToAdminList']),
			MapWrite([self::class, 'adminListToDB']),
		] public array $admin_list,
		public string $module,
		public string $name,
		public string $description,
		public ?int $sort=null,
		public ?string $file=null,
	) {
	}

	/** @return list<AccessLevel> */
	public static function dbToAdminList(string $adminList): array {
		$result = [];
		foreach (explode(',', $adminList) as $admin) {
			$result[] = AccessLevel::from($admin);
		}
		return $result;
	}

	/** @param list<AccessLevel> $adminList */
	public static function adminListToDB(array $adminList): string {
		return implode(
			',',
			array_map(
				static fn (AccessLevel $admin) => $admin->value,
				$adminList
			)
		);
	}
}
