<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE\Migrations;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	Types\SchemaMigration,
};
use Nadybot\Modules\COMMENT_MODULE\{Comment, ReputationController};
use Psr\Log\LoggerInterface;
use Throwable;

#[NCA\Migration(order: 2021_05_01_18_44_04, shared: true)]
class MigrateReputationTable implements SchemaMigration {
	#[NCA\Inject]
	private ReputationController $reputationController;

	public function migrate(LoggerInterface $logger, DB $db): void {
		if (!$db->schema()->hasTable('reputation')) {
			return;
		}

		/** @var Collection<int,object{"id":int,"name":string,"reputation":string,"comment":string,"by":string,"dt":int}> */
		$oldData = $db->table('reputation')->get();
		if ($oldData->count() === 0) {
			$logger->info('Reputation table empty, no need to convert anything');
			$db->schema()->dropIfExists('reputation');
			return;
		}
		$logger->info('Converting {num_entries} DB entries from reputation to comments', [
			'num_entries' => $oldData->count(),
		]);
		$cat = $this->reputationController->getReputationCategory();
		try {
			foreach ($oldData as $row) {
				$db->table(Comment::getTable())
					->insert([
						'category' => $cat->name,
						'character' => $row->name,
						'comment' => "{$row->reputation} {$row->comment}",
						'created_at' => $row->dt,
						'created_by' => $row->by,
					]);
			}
		} catch (Throwable $e) {
			$logger->warning('Error during the conversion of the reputation table: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		}
		$logger->info(
			'Conversion of reputation table finished successfully, removing old table'
		);
		$db->schema()->dropIfExists('reputation');
	}
}
