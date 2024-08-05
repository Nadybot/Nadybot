<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use function Safe\{json_decode, json_encode};
use InvalidArgumentException;
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportCharacter,
	ExporterInterface,
	ImporterInterface,
	ModuleInstance
};

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('polls'),
	NCA\Importer('polls', ExportPoll::class),
]
class PollsExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	/** @return list<ExportPoll> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(Poll::getTable())
			->asObj(Poll::class)
			->map(static function (Poll $poll) use ($db): ExportPoll {
				$export = new ExportPoll(
					author: new ExportCharacter(name: $poll->author),
					question: $poll->question,
					answers: [],
					startTime: $poll->started,
					endTime: $poll->started + $poll->duration,
				);
				$answers = [];
				foreach (json_decode($poll->possible_answers, false) as $answer) {
					$answers[$answer] ??= new ExportAnswer(
						answer: $answer,
						votes: [],
					);
				}

				$votes = $db->table(Vote::getTable())
					->where('poll_id', $poll->id)
					->asObj(Vote::class);
				foreach ($votes as $vote) {
					if (!isset($vote->answer)) {
						continue;
					}
					$answers[$vote->answer] ??= new ExportAnswer(
						answer: $vote->answer,
						votes: [],
					);
					$answers[$vote->answer]->votes []= new ExportVote(
						character: new ExportCharacter(name: $vote->author),
						voteTime: $vote->time,
					);
				}
				$export->answers = array_values($answers);
				return $export;
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_polls} polls', [
			'num_polls' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all polls');
			$db->table(Vote::getTable())->truncate();
			$db->table(Poll::getTable())->truncate();
			foreach ($data as $poll) {
				if (!($poll instanceof ExportPoll)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$this->importPoll($db, $poll);
			}
		} catch (Throwable $e) {
			$logger->error('{error}. Rolling back changes.', [
				'error' => rtrim($e->getMessage(), '.'),
				'exception' => $e,
			]);
			$db->rollback();
			return;
		}
		$db->commit();
		$logger->notice('All polls imported');
	}

	/** Import a single poll and its votes */
	private function importPoll(DB $db, ExportPoll $poll): void {
		$dbPoll = new Poll(
			author: $poll->author?->tryGetName() ?? $this->config->main->character,
			question: $poll->question,
			possible_answers: json_encode(
				array_map(
					static fn (ExportAnswer $answer): string => $answer->answer,
					$poll->answers??[]
				),
			),
			started: $poll->startTime ?? time(),
			duration: ($poll->endTime ?? time()) - ($poll->startTime ?? time()),
			status: VoteController::STATUS_STARTED,
		);
		$db->insert($dbPoll);
		foreach ($poll->answers??[] as $answer) {
			foreach ($answer->votes??[] as $vote) {
				$db->insert(new Vote(
					poll_id: $dbPoll->id,
					author: $vote->character?->tryGetName() ?? 'Unknown',
					answer: $answer->answer,
					time: $vote->voteTime ?? time(),
				));
			}
		}
	}
}
