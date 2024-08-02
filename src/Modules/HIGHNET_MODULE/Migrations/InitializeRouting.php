<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE\Migrations;

use Nadybot\Core\DBSchema\{Route, RouteHopColor, RouteHopFormat};
use Nadybot\Core\{Attributes as NCA, Config\BotConfig, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_05_31_12_53_12)]
class InitializeRouting implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$hops = ['web', strlen($this->config->general->orgName) ? 'aoorg' : "aopriv({$this->config->main->character})"];
		foreach ($hops as $hop) {
			$route = [
				'source' => 'highnet(*)',
				'destination' => $hop,
				'two_way' => false,
			];
			$db->table(Route::getTable())->insert($route);

			$route = [
				'source' => $hop,
				'destination' => 'highnet',
				'two_way' => false,
			];
			$db->table(Route::getTable())->insert($route);
		}

		$db->table(RouteHopFormat::getTable())->insert([
			'hop' => 'highnet',
			'render' => true,
			'format' => '@%s',
		]);

		$db->table(RouteHopColor::getTable())->insert([
			'hop' => 'highnet',
			'tag_color' => '00EFFF',
			'text_color' => '00BFFF',
		]);
	}
}
