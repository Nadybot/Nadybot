<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayCommandHandler;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_25_19_11_22)]
class CreateDiscordMappingTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = DiscordGatewayCommandHandler::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 12);
			$table->string('discord_id', 50);
			$table->string('token', 32)->nullable();
			$table->integer('created');
			$table->integer('confirmed')->nullable();
			$table->unique(['name', 'discord_id']);
		});
	}
}
