<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\BANK_MODULE\WishlistController;

class CreateWishlists implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = WishlistController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->unsignedInteger("created_on");
			$table->string("created_by", 12)->index();
			$table->string("item", 200);
			$table->unsignedInteger("amount")->default(1);
			$table->string("from", 12)->nullable(true)->index();
		});

		$table = WishlistController::DB_TABLE_FULFILMENT;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->unsignedInteger("wish_id")->index();
			$table->unsignedInteger("amount")->default(1);
			$table->unsignedInteger("fulfilled_on");
			$table->string("fulfilled_by", 12);
		});
	}
}
