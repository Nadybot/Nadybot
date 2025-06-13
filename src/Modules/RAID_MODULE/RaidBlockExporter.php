<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportCharacter,
	ModuleInstance,
	Types\ExporterInterface,
	Types\ImporterInterface
};
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\Config\BotConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('raidBlocks'),
	NCA\Importer('raidBlocks', ExportRaidBlock::class),
]
class RaidBlockExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	/** @return list<ExportRaidBlock> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(RaidBlock::getTable())
			->orderBy('player')
			->asObj(RaidBlock::class)
			->map(static function (RaidBlock $block): ExportRaidBlock {
				$entry = new ExportRaidBlock(
					character: new ExportCharacter(name: $block->player),
					blockedFrom: ExportRaidBlockType::from($block->blocked_from),
					blockedBy: new ExportCharacter(name: $block->blocked_by),
					blockedReason: $block->reason,
					blockStart: $block->time,
				);
				if (isset($block->expiration)) {
					$entry->blockEnd = $block->expiration;
				}
				return $entry;
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_blocks} raid blocks', [
			'num_blocks' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all raid blocks');
			$db->table(RaidBlock::getTable())->truncate();
			foreach ($data as $block) {
				if (!($block instanceof ExportRaidBlock)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$name = $block->character->tryGetName();
				if (!isset($name)) {
					continue;
				}
				$db->insert(new RaidBlock(
					player: $name,
					blocked_from: $block->blockedFrom->value,
					blocked_by: $block->blockedBy?->tryGetName() ?? $this->config->main->character,
					reason: $block->blockedReason ?? 'No reason given',
					time: $block->blockStart ?? time(),
					expiration: $block->blockEnd,
				));
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
		$logger->notice('All raid blocks imported');
	}
}
