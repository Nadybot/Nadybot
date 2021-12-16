<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\ConfigFile;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;

class CreateDefaultRouting implements SchemaMigration {
	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public ConfigFile $config;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$route = new Route();
		$route->source = "web";
		$route->destination = Source::PRIV . "(" . $this->config->name . ")";
		$route->two_way = true;
		$db->insert($table, $route);

		$route = new Route();
		$route->source = "web";
		$route->destination = Source::ORG;
		$route->two_way = true;
		$db->insert($table, $route);
	}
}
