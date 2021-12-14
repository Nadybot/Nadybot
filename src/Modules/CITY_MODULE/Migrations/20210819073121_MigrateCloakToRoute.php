<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	DBSchema\Route,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Nadybot\Modules\CITY_MODULE\CloakController;

class MigrateCloakToRoute implements SchemaMigration {
	#[NCA\Inject]
	public CloakController $cloakController;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$route = new Route();
		$route->source = $this->cloakController->getChannelName();
		$route->destination = Source::ORG;
		$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
	}
}
