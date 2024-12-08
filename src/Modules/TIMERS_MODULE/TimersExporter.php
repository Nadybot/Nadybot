<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportChannel,
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
	NCA\Exporter('timers'),
	NCA\Importer('timers', ExportTimer::class),
]
class TimersExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[Inject]
	private BotConfig $config;

	#[Inject]
	private TimerController $timerController;

	/** @return list<ExportTimer> */
	public function export(DB $db, LoggerInterface $logger): array {
		$timers = $this->timerController->getAllTimers();
		$result = [];
		foreach ($timers as $timer) {
			$channels = array_values(
				array_diff(
					explode(
						',',
						str_replace(['guild', 'both', 'msg'], ['org', 'priv,org', 'tell'], $timer->mode??'')
					),
					['']
				)
			);
			$data = new ExportTimer(
				startTime: $timer->settime,
				timerName: $timer->name,
				endTime: $timer->endtime ?? $timer->settime,
				createdBy: new ExportCharacter(name: $timer->owner),
				channels: array_map(ExportChannel::fromNadybot(...), $channels),
				alerts: [],
			);
			if (isset($timer->data) && (int)$timer->data > 0) {
				$data->repeatInterval = (int)$timer->data;
			}
			foreach ($timer->alerts as $alert) {
				$data->alerts []= new ExportAlert(
					time: $alert->time,
					message: $alert->message,
				);
			}
			$result []= $data;
		}
		return $result;
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_timers} timers', [
			'num_timers' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all timers');
			$db->table(Timer::getTable())->truncate();
			$timerNum = 1;
			foreach ($data as $timer) {
				if (!($timer instanceof ExportTimer)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}
				$this->importTimer($db, $timer, $timerNum++);
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
		$logger->notice('All timers imported');
	}

	/** Import a single timer into the database */
	private function importTimer(DB $db, ExportTimer $timer, int $timerNum): void {
		$owner = $timer->createdBy?->tryGetName();
		$data = isset($timer->repeatInterval)
			? (string)$timer->repeatInterval
			: null;
		$entry = new Timer(
			name: $timer->timerName ?? $timer->createdBy?->tryGetName() ?? $this->config->main->character . "-{$timerNum}",
			owner: $owner ?? $this->config->main->character,
			data: $data,
			mode: $this->channelsToMode($timer->channels??[]),
			endtime: $timer->endTime,
			callback: isset($data) ? 'timercontroller.repeatingTimerCallback' : 'timercontroller.timerCallback',
			alerts: [],
			settime: $timer->startTime ?? time(),
		);
		foreach ($timer->alerts??[] as $alert) {
			$entry->alerts []= new Alert(
				message: $alert->message ?? "Timer <highlight>{$entry->name}<end> has gone off.",
				time: $alert->time,
			);
		}
		if (!count($entry->alerts)) {
			$entry->alerts []= new Alert(
				message: "Timer <highlight>{$entry->name}<end> has gone off.",
				time: $timer->endTime,
			);
		}
		$db->insert($entry);
	}

	/** @param list<ExportChannel> $channels */
	private function channelsToMode(array $channels): string {
		return implode(',', array_map(static fn (ExportChannel $channel): string => $channel->toNadybot(), $channels));
	}
}
