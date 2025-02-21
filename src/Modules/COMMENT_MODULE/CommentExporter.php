<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Types\AccessLevel;
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
	NCA\Exporter('comments'),
	NCA\Importer('comments', ExportComment::class),
]
class CommentExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private CommentController $commentController;

	/** @return list<ExportComment> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(Comment::getTable())
			->asObj(Comment::class)
			->map(static function (Comment $comment): ExportComment {
				return new ExportComment(
					comment: $comment->comment,
					targetCharacter: new ExportCharacter(name: $comment->character),
					createdBy: new ExportCharacter(name: $comment->created_by),
					createdAt: $comment->created_at,
					category: $comment->category,
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_comments} comment(s)', [
			'num_comments' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all comments');
			$db->table(Comment::getTable())->truncate();
			foreach ($data as $comment) {
				if (!($comment instanceof ExportComment)) {
					throw new InvalidArgumentException('CommentExporter::import() called with wrong data');
				}
				$name = $comment->targetCharacter->tryGetName();
				if (!isset($name)) {
					continue;
				}
				$createdBy = $comment->createdBy?->tryGetName();
				$entry = new Comment(
					comment: $comment->comment,
					character: $name,
					created_by: $createdBy ?? $this->config->main->character,
					created_at: $comment->createdAt ?? time(),
					category: $comment->category ?? 'admin',
				);
				if ($this->commentController->getCategory($entry->category) === null) {
					$cat = new CommentCategory(
						name: $entry->category,
						created_by: $this->config->main->character,
						created_at: time(),
						min_al_read: AccessLevel::Mod,
						min_al_write: AccessLevel::Admin,
						user_managed: true,
					);
					$db->insert($cat);
				}
				$db->insert($entry);
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
		$logger->notice('All comments imported');
	}
}
