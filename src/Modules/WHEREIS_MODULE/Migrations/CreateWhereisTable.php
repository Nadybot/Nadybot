<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211207105015, shared: true)]
class CreateWhereisTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "whereis";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->primary();
			$table->string("name", 100);
			$table->text("answer");
			$table->text("keywords")->nullable();
			$table->integer("playfield_id")->index();
			$table->integer("xcoord");
			$table->integer("ycoord");
		});
	}
}
