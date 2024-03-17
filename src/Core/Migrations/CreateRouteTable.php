<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210722072916)]
class CreateRouteTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("source", 100);
			$table->string("destination", 100);
			$table->boolean("two_way")->default(false);
			$table->unsignedInteger("disabled_until")->nullable(true);
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
