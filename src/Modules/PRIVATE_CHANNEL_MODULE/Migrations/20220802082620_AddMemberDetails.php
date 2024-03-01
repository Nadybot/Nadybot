<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Core\{AccessManager, DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController;

class AddMemberDetails implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = PrivateChannelController::DB_TABLE;
		$db->table($table)->whereNull("autoinv")->update(["autoinv" => 0]);
		$db->schema()->table($table, function (Blueprint $table) {
			$table->integer("autoinv")->nullable(false)->change();
		});
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedInteger("joined")->nullable(true);
			$table->string("added_by", 12)->nullable(true);
		});
		$members = $db->table($table)->select("name")->pluckStrings("name");
		$time = time();
		$db->table($table)->update(['joined' => $time]);
		// Try to backfill the "joined" value from the audit table
		foreach ($members as $member) {
			/** @var ?Audit */
			$audit = $db->table(AccessManager::DB_TABLE)
				->where("actee", $member)
				->where("action", AccessManager::ADD_RANK)
				->orderBy("time")
				->orderBy("id")
				->limit(1)
				->asObj(Audit::class)
				->first();

			if (isset($audit)) {
				$db->table($table)
				->where("name", $member)
				->update([
					'joined' => $audit->time->getTimestamp(),
					'added_by' => strlen($audit->actor) ? $audit->actor : null,
				]);
			}
		}
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedInteger("joined")->nullable(false)->change();
		});
	}
}
