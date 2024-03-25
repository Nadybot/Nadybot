<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Roll;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_06_53_31, shared: true)]
class CreateRollTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'roll';
		if ($db->schema()->hasTable('roll')) {
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->id('id')->change();
				$table->text('options')->change();
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->integer('time')->nullable();
			$table->string('name', 255)->nullable();
			$table->text('options')->nullable();
			$table->string('result', 255)->nullable();
		});
	}
}
