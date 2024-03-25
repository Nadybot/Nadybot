<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_28_08_24_41, shared: true)]
class CreateTrickleTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'trickle';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->integer('skill_id');
			$table->string('groupName', 20);
			$table->string('name', 30);
			$table->decimal('amountAgi', 3, 1);
			$table->decimal('amountInt', 3, 1);
			$table->decimal('amountPsy', 3, 1);
			$table->decimal('amountSta', 3, 1);
			$table->decimal('amountStr', 3, 1);
			$table->decimal('amountSen', 3, 1);
		});
	}
}
