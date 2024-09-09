<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\NEWS_MODULE\NewsConfirmed;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_27_05_31_15, shared: true)]
class CreateNewsConfirmedTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = NewsConfirmed::getTable();
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->index();
			$table->string('player', 20)->index();
			$table->integer('time');
			$table->unique(['id', 'player']);
		});
	}
}
