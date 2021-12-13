<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DB;
use Nadybot\Core\Nadybot;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;

class MigrateUIToRoute implements SchemaMigration {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$route = new Route();
		$route->source = Source::SYSTEM . "(webui)";
		/** @psalm-suppress DocblockTypeContradiction */
		if (strlen($this->chatBot->vars["my_guild"]??"")) {
			$route->destination = Source::ORG;
		} else {
			$route->destination = Source::PRIV . "(" . $this->chatBot->vars["name"] . ")";
		}
		$db->insert($table, $route);
	}
}
