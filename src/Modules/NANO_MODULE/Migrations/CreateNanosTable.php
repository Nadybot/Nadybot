<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_220_903_062_802, shared: true)]
class CreateNanosTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'nanos';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedInteger('crystal_id')->nullable()->index();
			$table->unsignedInteger('nano_id')->primary();
			$table->unsignedInteger('ql')->index();
			$table->string('crystal_name', 70)->nullable();
			$table->string('nano_name', 70);
			$table->string('school', 26);
			$table->string('strain', 45);
			$table->integer('strain_id')->index();
			$table->string('sub_strain', 45);
			$table->string('professions', 50);
			$table->string('location', 45);
			$table->integer('nano_cost');
			$table->boolean('froob_friendly')->index();
			$table->integer('sort_order')->index();
			$table->boolean('nano_deck')->index();
			$table->unsignedInteger('min_level')->nullable(true)->index();
			$table->unsignedInteger('spec')->nullable(true)->index();
			$table->unsignedInteger('mm')->nullable(true)->index();
			$table->unsignedInteger('bm')->nullable(true)->index();
			$table->unsignedInteger('pm')->nullable(true)->index();
			$table->unsignedInteger('si')->nullable(true)->index();
			$table->unsignedInteger('ts')->nullable(true)->index();
			$table->unsignedInteger('mc')->nullable(true)->index();
		});
	}
}
