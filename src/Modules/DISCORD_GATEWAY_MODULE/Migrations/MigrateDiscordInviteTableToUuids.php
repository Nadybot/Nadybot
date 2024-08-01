<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\{DBDiscordInvite};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_21_04_00)]
class MigrateDiscordInviteTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			DBDiscordInvite::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('token', 10)->unique();
				$table->string('character', 12);
				$table->unsignedInteger('expires')->nullable()->index();
			},
		);
	}
}
