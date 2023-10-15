<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\BANK_MODULE\WishlistController;

class AddWishExpiration implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = WishlistController::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table) {
			$table->unsignedInteger("expires_on")->nullable(true);
		});
	}
}
