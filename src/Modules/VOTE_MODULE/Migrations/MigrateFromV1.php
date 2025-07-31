<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use function Safe\json_encode;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\{Poll, Vote, VoteController};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_28_08_33_08)]
class MigrateFromV1 implements SchemaMigration {
	public const DB_OLD_VOTE = 'vote_<myname>';

	public function migrate(LoggerInterface $logger, DB $db): void {
		if (!$db->schema()->hasTable(self::DB_OLD_VOTE)) {
			return;
		}
		$logger->info('Converting old vote format into poll format');

		/** @var \stdClass[] */
		$oldPolls = $db->table(self::DB_OLD_VOTE)
			->whereNotNull('duration')
			->get()
			->toArray();
		foreach ($oldPolls as $oldPoll) {
			$id = $db->table(Poll::getTable())->insertGetId([
				'author' => (string)$oldPoll->author,
				'question' => (string)$oldPoll->question,
				'possible_answers' => json_encode(explode(VoteController::DELIMITER, (string)$oldPoll->answer)),
				'started' => (int)$oldPoll->started,
				'duration' => (int)$oldPoll->duration,
				'status' => (int)$oldPoll->status,
			]);

			/** @var \stdClass[] */
			$oldVotes = $db->table(self::DB_OLD_VOTE)
				->where('question', (string)$oldPoll->question)
				->whereNull('duration')
				->get()->toArray();
			foreach ($oldVotes as $oldVote) {
				if (!$db->table(Vote::getTable())->insert([
					'poll_id' => $id,
					'author' => (string)$oldVote->author,
					'answer' => (string)$oldVote->answer,
				])) {
					$logger->error('Cannot convert old votes into new format.');
					return;
				}
			}
			$db->table(self::DB_OLD_VOTE)
				->where('question', (string)$oldPoll->question)
				->delete();
			$logger->info('Poll "{question}" converted to new poll system', [
				'question' => $oldPoll->question,
			]);
		}
		$db->schema()->dropIfExists(self::DB_OLD_VOTE);
		$logger->info('Conversion completed');
	}
}
