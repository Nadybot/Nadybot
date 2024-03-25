<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_23_13_33_02)]
class MakeValueText implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Preferences::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('sender', 15)->index()->change();
			$table->string('name', 30)->index()->change();
			$table->text('value')->change();
		});
	}
}
