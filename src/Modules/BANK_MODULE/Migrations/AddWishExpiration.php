<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\BANK_MODULE\Wish;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_08_25_07_52_11)]
class AddWishExpiration implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Wish::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unsignedInteger('expires_on')->nullable(true);
		});
	}
}
