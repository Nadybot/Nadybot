<?php declare(strict_types=1);

namespace Nadybot\Modules\SPIRITS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_12_07_10_31_53, shared: true)]
class CreateSpiritsdbTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'spiritsdb';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedInteger('id')->primary();
			$table->string('name', 45);
			$table->unsignedSmallInteger('ql')->index();
			$table->string('spot', 6)->index();
			$table->unsignedSmallInteger('level')->index();
			$table->unsignedSmallInteger('agility')->index();
			$table->unsignedSmallInteger('sense')->index();
			$table->index(['spot', 'ql']);
		});
	}
}
