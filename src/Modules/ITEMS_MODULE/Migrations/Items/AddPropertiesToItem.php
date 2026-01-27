<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Items;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2026_01_23_20_33_00, shared: true)]
class AddPropertiesToItem implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->table(AODBEntry::getTable())->truncate();
		$db->schema()->table(AODBEntry::getTable(), static function (Blueprint $table): void {
			$table->integer('properties')->nullable(false);
		});
	}
}
