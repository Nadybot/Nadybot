<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Auctions;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAID_MODULE\AuctionController;

class CreateAuctionTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AuctionController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function(Blueprint $table) {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->integer("raid_id")->nullable()->index();
			$table->text("item");
			$table->string("auctioneer", 20);
			$table->integer("cost")->nullable();
			$table->string("winner", 20)->nullable();
			$table->integer("end");
			$table->boolean("reimbursed")->default(false);
		});
	}
}
