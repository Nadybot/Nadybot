<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Auctions;

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
		$route = new Route();
		$route->source = "auction(*)";
		$route->destination = Source::PRIV . "(" . $db->getMyname() . ")";
		$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
	}
}
