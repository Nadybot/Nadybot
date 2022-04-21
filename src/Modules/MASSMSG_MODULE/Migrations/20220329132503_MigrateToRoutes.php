<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE\Migrations;

use Nadybot\Core\{
	DB,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};

class MigrateToRoutes implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
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
