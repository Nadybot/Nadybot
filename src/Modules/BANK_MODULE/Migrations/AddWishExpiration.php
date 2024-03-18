<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\BANK_MODULE\WishlistController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20230825075211)]
class AddWishExpiration implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = WishlistController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedInteger("expires_on")->nullable(true);
		});
	}
}
