<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;

class CreateRouteTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("source", 100);
			$table->string("destination", 100);
			$table->boolean("two_way")->default(false);
		});
		if (strlen($db->getMyguild())) {
			$route = new Route();
			$route->source = Source::SYSTEM . "(status)";
			$route->destination = Source::ORG;
			$db->insert($table, $route);
		}

		$route = new Route();
		$route->source = Source::SYSTEM . "(status)";
		$route->destination = Source::PRIV . "(" . $db->getMyname() . ")";
		$db->insert($table, $route);
	}
}
