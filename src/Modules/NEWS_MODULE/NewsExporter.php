<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

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
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('news'),
	NCA\Importer('news', ExportNews::class),
]
class NewsExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	/** @return list<ExportNews> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(News::getTable())
			->asObj(News::class)
			->map(static function (News $topic) use ($db): ExportNews {
				$data = new ExportNews(
					author: new ExportCharacter(name: $topic->name),
					uuid: $topic->id->toString(),
					addedTime: $topic->time,
					news: $topic->news,
					pinned: $topic->sticky,
					deleted: $topic->deleted,
					confirmedBy: [],
				);

				$confirmations = $db->table(NewsConfirmed::getTable())
					->where('id', $topic->id)
					->asObj(NewsConfirmed::class);
				foreach ($confirmations as $confirmation) {
					$data->confirmedBy []= new ExportNewsConfirmation(
						character: new ExportCharacter(name: $confirmation->player),
						confirmationTime: $confirmation->time,
					);
				}
				return $data;
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_news} news', [
			'num_news' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all news');
			$db->table(NewsConfirmed::getTable())->truncate();
			$db->table(News::getTable())->truncate();
			foreach ($data as $item) {
				if (!($item instanceof ExportNews)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$news = new News(
					time: $item->addedTime ?? time(),
					id: isset($item->uuid) ? Uuid::fromString($item->uuid) : null,
					name: $item->author?->tryGetName() ?? $this->config->main->character,
					news: $item->news,
					sticky: $item->pinned ?? false,
					deleted: $item->deleted ?? false,
				);
				$db->insert($news);
				foreach ($item->confirmedBy??[] as $confirmation) {
					$name = $confirmation->character->tryGetName();
					if (!isset($name)) {
						continue;
					}
					$db->insert(new NewsConfirmed(
						id: $news->id,
						player: $name,
						time: $confirmation->confirmationTime ?? time(),
					));
				}
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
		$logger->notice('All news imported');
	}
}
