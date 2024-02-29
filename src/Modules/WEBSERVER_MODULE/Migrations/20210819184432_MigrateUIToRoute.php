<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};

class MigrateUIToRoute implements SchemaMigration {
	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$route = new Route();
		$route->source = Source::SYSTEM . "(webui)";
		if (strlen($this->config->orgName)) {
			$route->destination = Source::ORG;
		} else {
			$route->destination = Source::PRIV . "({$this->config->name})";
		}
		$db->insert($table, $route);
	}
}
