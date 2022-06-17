<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	DB,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};

class CreateRouteTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("source", 100);
			$table->string("destination", 100);
			$table->boolean("two_way")->default(false);
		});
		if (strlen($db->getMyguild())) {
			$route = [
				"source" => Source::SYSTEM . "(status)",
				"destination" => Source::ORG,
				"two_way" => false,
			];
			$db->table($table)->insert($route);
		}

		$route = [
			"source" => Source::SYSTEM . "(status)",
			"destination" => Source::PRIV . "(" . $db->getMyname() . ")",
			"two_way" => false,
		];
		$db->table($table)->insert($route);
	}
}
