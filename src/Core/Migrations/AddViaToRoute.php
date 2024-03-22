<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_006_141_548)]
class AddViaToRoute implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_COLORS;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('via', 50)->nullable(true);
			$table->dropUnique(['hop', 'where']);
			$table->unique(['hop', 'where', 'via']);
		});

		$table = Source::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('via', 50)->nullable(true);
			$table->dropUnique(['hop', 'where']);
			$table->unique(['hop', 'where', 'via']);
		});
	}
}
