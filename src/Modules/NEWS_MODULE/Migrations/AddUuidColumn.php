<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	Types\SchemaMigration,
};
use Nadybot\Modules\NEWS_MODULE\News;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use stdClass;

#[NCA\Migration(order: 2022_01_26_10_34_56, shared: true)]
class AddUuidColumn implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = News::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('uuid', 36)->nullable(true);
		});
		$db->table($table)->get()->each(static function (stdClass $data) use ($db, $table): void {
			$db->table($table)->where('id', (int)$data->id)->update([
				'uuid' => Uuid::uuid7()->toString(),
			]);
		});
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('uuid', 36)->nullable(false)->unique()->index()->change();
		});
	}
}
