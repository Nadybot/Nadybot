<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20220329132503)]
class MigrateToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$botName = $db->getMyname();

		$channels = ["aoorg", "aopriv({$botName})"];
		$types = ["mass-message", "mass-invite"];
		foreach ($channels as $channel) {
			foreach ($types as $type) {
				$route = [
					"source" => Source::SYSTEM . "({$type})",
					"destination" => $channel,
					"two_way" => false,
				];
				$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
			}
		}
	}
}
