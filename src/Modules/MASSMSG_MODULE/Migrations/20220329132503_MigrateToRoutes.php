<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;

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
