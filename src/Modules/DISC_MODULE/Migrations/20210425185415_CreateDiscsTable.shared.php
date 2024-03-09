<?php declare(strict_types=1);

namespace Nadybot\Modules\DISC_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateDiscsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "discs";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("disc_id")->primary();
			$table->integer("crystal_id");
			$table->integer("crystal_ql");
			$table->integer("disc_ql");
			$table->string("disc_name", 75)->index();
			$table->string("crystal_name", 70);
			$table->string("comment", 50)->nullable();
		});
	}
}
