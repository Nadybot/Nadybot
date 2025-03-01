<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\TRICKLE_MODULE\Trickle;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2025_02_08_17_46_12, shared: true)]
class CreateTrickleTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Trickle::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->integer('skill_id');
			$table->string('groupName', 20);
			$table->decimal('amountAgi', 3, 1);
			$table->decimal('amountInt', 3, 1);
			$table->decimal('amountPsy', 3, 1);
			$table->decimal('amountSta', 3, 1);
			$table->decimal('amountStr', 3, 1);
			$table->decimal('amountSen', 3, 1);
		});
	}
}
