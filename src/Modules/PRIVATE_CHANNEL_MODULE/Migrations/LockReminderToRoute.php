<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{Attributes as NCA, Config\BotConfig, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_02_28_15_28_17)]
class LockReminderToRoute implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$route = [
			'source' => Source::SYSTEM . '(lock-reminder)',
			'destination' => Source::PRIV . "({$this->config->main->character})",
			'two_way' => false,
		];
		$db->table(Route::getTable())->insert($route);

		$route = [
			'source' => Source::SYSTEM . '(lock-reminder)',
			'destination' => Source::ORG,
			'two_way' => false,
		];
		$db->table(Route::getTable())->insert($route);
	}
}
