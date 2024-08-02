<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\History;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\GUILD_MODULE\OrgHistory;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_05_03_03, shared: true)]
class CreateOrgHistoryTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = OrgHistory::getTable();
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->id('id')->change();
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->text('actor')->nullable();
			$table->text('actee')->nullable();
			$table->text('action')->nullable();
			$table->text('organization')->nullable();
			$table->integer('time')->nullable();
		});
	}
}
