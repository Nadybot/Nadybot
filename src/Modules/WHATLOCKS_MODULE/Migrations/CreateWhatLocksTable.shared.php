<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210428090455)]
class CreateWhatLocksTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "what_locks";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("item_id")->index();
			$table->integer("skill_id")->index();
			$table->integer("duration")->index();
		});
	}
}
