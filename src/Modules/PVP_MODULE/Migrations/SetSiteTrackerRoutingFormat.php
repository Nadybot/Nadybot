<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{Attributes as NCA, DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20230308163957)]
class SetSiteTrackerRoutingFormat implements SchemaMigration {
	#[NCA\Inject]
	private MessageHub $messageHub;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$towerColor = "F06AED";
		$hopColor = [
			"hop" => 'site-tracker',
			"tag_color" => $towerColor,
			"text_color" => null,
		];
		$db->table(MessageHub::DB_TABLE_COLORS)->insert($hopColor);

		$hopFormat = [
			"hop" => 'site-tracker',
			"format" => "SITE-TRACKER-%s",
			"render" => true,
		];
		$db->table(Source::DB_TABLE)->insert($hopFormat);

		$this->messageHub->loadTagFormat();
	}
}
