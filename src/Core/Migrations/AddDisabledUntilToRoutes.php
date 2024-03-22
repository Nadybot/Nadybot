<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_220_624_121_501)]
class AddDisabledUntilToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		if ($db->schema()->hasColumn($table, 'disabled_until')) {
			return;
		}
		$db->schema()->table($table, static function (Blueprint $table) {
			$table->unsignedInteger('disabled_until')->nullable(true);
		});
	}
}
