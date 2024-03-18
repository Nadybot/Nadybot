<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Pocketboss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211207102100, shared: true)]
class CreatePocketbossTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "pocketboss";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->primary();
			$table->string("pb", 30)->index();
			$table->string("pb_location", 30);
			$table->string("bp_mob", 100);
			$table->smallInteger("bp_lvl");
			$table->string("bp_location", 50);
			$table->string("type", 15)->index();
			$table->string("slot", 15)->index();
			$table->string("line", 15)->index();
			$table->smallInteger("ql");
			$table->integer("itemid");
		});
	}
}
