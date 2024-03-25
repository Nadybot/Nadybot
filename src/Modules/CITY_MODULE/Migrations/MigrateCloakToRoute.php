<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Nadybot\Modules\CITY_MODULE\CloakController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_19_07_31_21)]
class MigrateCloakToRoute implements SchemaMigration {
	#[NCA\Inject]
	private CloakController $cloakController;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$route = [
			'source' => $this->cloakController->getChannelName(),
			'destination' => Source::ORG,
			'two_way' => false,
		];
		$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
	}
}
