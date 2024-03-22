<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_425_093_644)]
class CreatePreferencesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Preferences::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('sender', 30);
			$table->string('name', 30);
			$table->string('value', 400);
		});
	}
}
