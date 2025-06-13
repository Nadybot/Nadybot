<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Player;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_12_07_09_11_41, shared: true)]
class IndexPlayersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Player::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->index(['dimension', 'name']);
			$table->index(['guild_id']);
		});
	}
}
