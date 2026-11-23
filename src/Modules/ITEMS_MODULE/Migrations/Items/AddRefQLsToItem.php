<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Items;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;
use Psr\Log\LoggerInterface;

/**
 * Store the name and reference QLs of both templates of an item
 *
 * The table is emptied, so the item data is imported again in the setup.
 */
#[NCA\Migration(order: 2026_11_23_20_10_00, shared: true)]
class AddRefQLsToItem implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->table(AODBEntry::getTable())->truncate();
		$db->schema()->table(AODBEntry::getTable(), static function (Blueprint $table): void {
			$table->string('high_name', 150)->nullable();
			$table->integer('low_ref_ql')->nullable();
			$table->integer('high_ref_ql')->nullable();
		});
	}
}
