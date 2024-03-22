<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Buff;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_207_084_228, shared: true)]
class CreateItemTypesDB implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->schema()->dropIfExists('item_types');
		$db->schema()->create('item_types', static function (Blueprint $table): void {
			$table->integer('item_id')->index();
			$table->string('item_type', 15)->index();
		});
	}
}
