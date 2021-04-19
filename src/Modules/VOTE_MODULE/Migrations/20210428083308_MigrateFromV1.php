<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Nadybot\Modules\VOTE_MODULE\VoteController;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class MigrateFromV1 implements SchemaMigration {
	public const DB_OLD_VOTE = "vote_<myname>";

	public function migrate(LoggerWrapper $logger, DB $db): void {
		if (!$db->schema()->hasTable(self::DB_OLD_VOTE)) {
			return;
		}
		$logger->log("INFO", "Converting old vote format into poll format");
		$oldPolls = $db->table(self::DB_OLD_VOTE)
			->whereNotNull("duration")
			->asObj()
			->toArray();
		foreach ($oldPolls as $oldPoll) {
			$id = $db->table(VoteController::DB_POLLS)->insertGetId([
				"author" => $oldPoll->author,
				"question" => $oldPoll->question,
				"possible_answers" => json_encode(explode(VoteController::DELIMITER, $oldPoll->answer)),
				"started" => (int)$oldPoll->started,
				"duration" => (int)$oldPoll->duration,
				"status" => (int)$oldPoll->status,
			]);
			$oldVotes = $db->table(self::DB_OLD_VOTE)
				->where("question", $oldPoll->question)
				->whereNull("duration")
				->asObj()->toArray();
			foreach ($oldVotes as $oldVote) {
				if (!$db->table(VoteController::DB_VOTES)->insert([
					"poll_id" => $id,
					"author" => $oldVote->author,
					"answer" => $oldVote->answer
				])) {
					$logger->log("ERROR", "Cannot convert old votes into new format.");
					return;
				}
			}
			$db->table(self::DB_OLD_VOTE)
				->where("question", $oldPoll->question)
				->delete();
			$logger->log("INFO", "Poll \"{$oldPoll->question}\" converted to new poll system");
		}
		$db->schema()->dropIfExists(self::DB_OLD_VOTE);
		$logger->log("INFO", "Conversion completed");
	}
}
