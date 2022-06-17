<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	LoggerWrapper,
	SchemaMigration,
	Util,
};
use stdClass;

class AddUuidColumn implements SchemaMigration {
	#[NCA\Inject]
	public Util $util;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "news";
		$db->schema()->table($table, function (Blueprint $table) {
			$table->string("uuid", 36)->nullable(true);
		});
		$db->table($table)->get()->each(function (stdClass $data) use ($db, $table): void {
			$db->table($table)->where("id", (int)$data->id)->update([
				"uuid" => $this->util->createUUID(),
			]);
		});
		$db->schema()->table($table, function (Blueprint $table) {
			$table->string("uuid", 36)->nullable(false)->unique()->index()->change();
		});
	}
}
