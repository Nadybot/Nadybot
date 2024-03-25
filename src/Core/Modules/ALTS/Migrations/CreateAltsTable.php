<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_23_10_26_50, shared: true)]
class CreateAltsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'alts';
		if ($db->schema()->hasTable($table)) {
			if (!$db->schema()->hasColumn('alts', 'validated')) {
				return;
			}
			$db->schema()->table('alts', static function (Blueprint $table): void {
				$table->renameColumn('validated', 'validated_by_alt');
			});
			$db->schema()->table('alts', static function (Blueprint $table): void {
				$table->boolean('validated_by_alt')->nullable()->default(false)->change();
				$table->boolean('validated_by_main')->nullable()->default(false);
				$table->string('added_via', 15)->nullable();
			});
			$myName = $db->getMyname();
			$db->table('alts')->update(['validated_by_main' => true, 'added_via' => $myName]);
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('alt', 25)->primary();
			$table->string('main', 25)->nullable();
			$table->boolean('validated_by_main')->nullable()->default(false);
			$table->boolean('validated_by_alt')->nullable()->default(false);
			$table->string('added_via', 15)->nullable();
		});
	}
}
