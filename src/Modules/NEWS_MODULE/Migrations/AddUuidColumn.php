<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	SchemaMigration,
	Util,
};
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\Migration(order: 2022_01_26_10_34_56, shared: true)]
class AddUuidColumn implements SchemaMigration {
	#[NCA\Inject]
	private Util $util;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'news';
		$db->schema()->table($table, static function (Blueprint $table) {
			$table->string('uuid', 36)->nullable(true);
		});
		$db->table($table)->get()->each(function (stdClass $data) use ($db, $table): void {
			$db->table($table)->where('id', (int)$data->id)->update([
				'uuid' => $this->util->createUUID(),
			]);
		});
		$db->schema()->table($table, static function (Blueprint $table) {
			$table->string('uuid', 36)->nullable(false)->unique()->index()->change();
		});
	}
}
