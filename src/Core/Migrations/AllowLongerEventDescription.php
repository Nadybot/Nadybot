<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\EventCfg;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_28_17_19_22)]
class AllowLongerEventDescription implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = EventCfg::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('description', 255)->nullable(false)->change();
		});
	}
}
