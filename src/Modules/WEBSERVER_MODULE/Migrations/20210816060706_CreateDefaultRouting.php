<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Route,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};

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
