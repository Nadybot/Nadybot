<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\{MapRead, MapWrite};
use Nadybot\Core\DBRow;
use Nadybot\Core\Types\AccessLevel;

/** This represents a help-topic search result */
class HelpTopic extends DBRow {
	/**
	 * @param list<AccessLevel> $admin_list  A list of access levels required to execute the
	 *                                       command. If you are not allowed to execute the
	 *                                       command in any of the permission sets, you won't
	 *                                       be able to see its help.
	 * @param string            $module      Name of the module that defines the command
	 * @param string            $name        Name of the help topic/command
	 * @param string            $description Description to display
	 * @param null|int          $sort        Sort order
	 * @param null|string       $file        The file that defines the help/command
	 */
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
