<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, EventManager, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_230_328_171_922)]
class AllowLongerEventDescription implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = EventManager::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('description', 255)->nullable(false)->change();
		});
	}
}
