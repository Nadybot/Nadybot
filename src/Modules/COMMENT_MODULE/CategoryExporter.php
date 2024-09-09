<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	ExportCharacter,
	ModuleInstance,
	Types\ExporterInterface,
	Types\ImporterInterface
};
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('commentCategories'),
	NCA\Importer('commentCategories', ExportCategory::class),
]
class CategoryExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private CommentController $commentController;

	/** @return list<ExportCategory> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(CommentCategory::getTable())
			->asObj(CommentCategory::class)
			->map(static function (CommentCategory $category): ExportCategory {
				return new ExportCategory(
					name: $category->name,
					createdBy: new ExportCharacter(name: $category->created_by),
					createdAt: $category->created_at,
					minRankToRead: $category->min_al_read,
					minRankToWrite: $category->min_al_write,
					systemEntry: !$category->user_managed,
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_categories} comment categories', [
			'num_categories' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all user-managed comment categories');
			$db->table(CommentCategory::getTable())
				->where('user_managed', true)
				->delete();
			foreach ($data as $category) {
				if (!($category instanceof ExportCategory)) {
					throw new InvalidArgumentException('CategoryExporter::import() called with wrong data');
				}
				$oldEntry = $this->commentController->getCategory($category->name);
				$createdBy = $category->createdBy?->tryGetName();
				$entry = new CommentCategory(
					name: $category->name,
					created_by: $createdBy ?? $this->config->main->character,
					created_at: $category->createdAt ?? time(),
					min_al_read: $rankMap[$category->minRankToRead] ?? 'mod',
					min_al_write: $rankMap[$category->minRankToWrite] ?? 'admin',
				);

				$entry->user_managed = isset($oldEntry) ? $oldEntry->user_managed : !($category->systemEntry ?? false);
				if (isset($oldEntry)) {
					$db->update($entry);
				} else {
					$db->insert($entry);
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
		$logger->notice('All comment categories imported');
	}
}
