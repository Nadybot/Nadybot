<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{Attributes as NCA, ConfigFile, DB, LoggerWrapper, MessageHub, SchemaMigration};

class LockReminderToRoute implements SchemaMigration {
	#[NCA\Inject]
	public ConfigFile $config;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$route = new Route();
		$route->source = Source::SYSTEM . '(lock-reminder)';
		$route->two_way = false;

		$route->destination = Source::PRIV . "({$this->config->name})";
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		$route->destination = Source::ORG;
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
	}
}
