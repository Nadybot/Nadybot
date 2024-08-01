<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_19_47_43)]
class MigrateCommentsTableToUuid implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = $this->getSetting($db, 'table_name_comments')?->value ?? 'comments_<myname>';
		$db->migrateIdToUuid(
			$table,
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('character', 15)->index();
				$table->string('created_by', 15);
				$table->integer('created_at');
				$table->string('category', 20)->index();
				$table->text('comment');
			},
			'id',
			'created_at'
		);
	}

	private function getSetting(DB $db, string $name): ?Setting {
		return $db->table(Setting::getTable())
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}
}
