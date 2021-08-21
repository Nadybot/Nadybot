<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;

class CreateRouteHopFormatTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = Source::DB_TABLE;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("hop", 25)->unique();
			$table->boolean("render")->default(true);
			$table->string("format", 50)->default('%s');
		});
		$db->table($table)->insert([
			[
				"hop" => Source::PRIV,
				"render" => true,
				"format" => "%s",
			],
			[
				"hop" => Source::TRADEBOT,
				"render" => false,
				"format" => "%s",
			],
			[
				"hop" => Source::RELAY,
				"render" => false,
				"format" => "%s"
			],
			[
				"hop" => Source::SYSTEM,
				"render" => false,
				"format" => "%s"
			],
			[
				"hop" => Source::DISCORD_PRIV,
				"render" => true,
				"format" => "#%s",
			],
			[
				"hop" => Source::DISCORD_MSG,
				"render" => true,
				"format" => "%s@Discord",
			],
			[
				"hop" => Source::TELL,
				"render" => true,
				"format" => "@%s",
			],
			[
				"hop" => "*",
				"render" => true,
				"format" => "%s",
			],
		]);
	}
}
