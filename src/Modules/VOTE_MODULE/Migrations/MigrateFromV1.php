<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use function Safe\json_encode;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};

use Nadybot\Modules\VOTE_MODULE\VoteController;
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210428083308)]
class MigrateFromV1 implements SchemaMigration {
	public const DB_OLD_VOTE = "vote_<myname>";

	public function migrate(LoggerInterface $logger, DB $db): void {
		if (!$db->schema()->hasTable(self::DB_OLD_VOTE)) {
			return;
		}
		$logger->info("Converting old vote format into poll format");
		$oldPolls = $db->table(self::DB_OLD_VOTE)
			->whereNotNull("duration")
			->get()
			->toArray();
		foreach ($oldPolls as $oldPoll) {
			$id = $db->table(VoteController::DB_POLLS)->insertGetId([
				"author" => (string)$oldPoll->author,
				"question" => (string)$oldPoll->question,
				"possible_answers" => json_encode(explode(VoteController::DELIMITER, (string)$oldPoll->answer)),
				"started" => (int)$oldPoll->started,
				"duration" => (int)$oldPoll->duration,
				"status" => (int)$oldPoll->status,
			]);
			$oldVotes = $db->table(self::DB_OLD_VOTE)
				->where("question", (string)$oldPoll->question)
				->whereNull("duration")
				->get()->toArray();
			foreach ($oldVotes as $oldVote) {
				if (!$db->table(VoteController::DB_VOTES)->insert([
					"poll_id" => $id,
					"author" => (string)$oldVote->author,
					"answer" => (string)$oldVote->answer,
				])) {
					$logger->error("Cannot convert old votes into new format.");
					return;
				}
			}
			$db->table(self::DB_OLD_VOTE)
				->where("question", (string)$oldPoll->question)
				->delete();
			$logger->info("Poll \"{$oldPoll->question}\" converted to new poll system");
		}
		$db->schema()->dropIfExists(self::DB_OLD_VOTE);
		$logger->info("Conversion completed");
	}
}
