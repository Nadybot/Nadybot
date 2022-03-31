<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE\Migrations;

use Nadybot\Core\{
	DB,
	DBSchema\Route,
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
				$route = new Route();
				$route->source = Source::SYSTEM . "({$type})";
				$route->destination = $channel;
				$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
			}
		}
	}
}
