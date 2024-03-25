<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_25_13_34_26, shared: true)]
class CreateBankTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'bank';
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 150)->nullable();
			$table->integer('lowid')->nullable();
			$table->integer('highid')->nullable();
			$table->integer('ql')->nullable();
			$table->string('player', 20)->nullable();
			$table->string('container', 150)->nullable();
			$table->integer('container_id')->nullable();
			$table->string('location', 150)->nullable();
		});
	}
}
