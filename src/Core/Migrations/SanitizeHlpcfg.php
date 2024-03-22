<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, HelpManager, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_207_155_633)]
class SanitizeHlpcfg implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = HelpManager::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('module', 50)->nullable(false)->change();
			$table->string('file', 255)->nullable(false)->change();
			$table->string('description', 75)->nullable(false)->change();
			$table->string('admin', 10)->nullable(false)->change();
			$table->integer('verify')->nullable(false)->change();
		});
	}
}
