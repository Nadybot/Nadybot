<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Buff;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_426_164_751, shared: true)]
class CreateBuffDBs implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->schema()->dropIfExists('item_buffs');
		$db->schema()->create('item_buffs', static function (Blueprint $table): void {
			$table->integer('item_id')->index();
			$table->integer('attribute_id')->index();
			$table->integer('amount');
		});

		$db->schema()->dropIfExists('skills');
		$db->schema()->create('skills', static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->string('name', 50);
			$table->string('unit', 10);
		});

		$db->schema()->dropIfExists('skill_alias');
		$db->schema()->create('skill_alias', static function (Blueprint $table): void {
			$table->integer('id');
			$table->string('name', 50);
		});

		$db->schema()->dropIfExists('buffs');
		$db->schema()->create('buffs', static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->integer('nano_id')->nullable()->index();
			$table->integer('disc_id')->nullable();
			$table->integer('use_id')->nullable();
			$table->string('name', 255)->nullable();
			$table->integer('ncu')->nullable();
			$table->integer('nanocost')->nullable();
			$table->integer('school')->nullable();
			$table->integer('strain')->nullable();
			$table->integer('duration')->nullable();
			$table->integer('attack')->nullable();
			$table->integer('recharge')->nullable();
			$table->integer('range')->nullable();
			$table->integer('initskill')->nullable();
			$table->boolean('froob_friendly')->nullable();
		});
	}
}
