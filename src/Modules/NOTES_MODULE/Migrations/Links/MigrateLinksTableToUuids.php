<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\Links;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\NOTES_MODULE\Link;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_12_14_00, shared: true)]
class MigrateLinksTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Link::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('name', 25);
				$table->string('website', 255);
				$table->string('comments', 255);
				$table->integer('dt');
			},
			'id',
			'dt'
		);
	}
}
