<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210821103901)]
class CreateRouteHopFormatTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Source::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("hop", 50);
			$table->string("where", 50)->nullable(true);
			$table->boolean("render")->default(true);
			$table->string("format", 50)->default('%s');
			$table->unique(["hop", "where"]);
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
				"format" => "%s",
			],
			[
				"hop" => Source::SYSTEM,
				"render" => false,
				"format" => "%s",
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
				"format" => "%s",
			],
			[
				"hop" => "*",
				"render" => true,
				"format" => "%s",
			],
		]);
	}
}
