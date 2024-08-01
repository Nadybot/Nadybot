<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\FUN_MODULE\Fun;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_08_23_05_00_54, shared: true)]
class CreateFunTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Fun::getTable();
		if ($db->schema()->hasTable($table)) {
			$db->schema()->drop($table);
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('type', 15)->index();
			$table->text('content');
		});
	}
}
