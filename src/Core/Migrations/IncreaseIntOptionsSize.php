<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_12_25_08_06_46)]
class IncreaseIntOptionsSize implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Setting::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->text('intoptions')->nullable(true)->change();
		});
	}
}
