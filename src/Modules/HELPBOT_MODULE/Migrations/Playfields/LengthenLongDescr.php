<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Playfields;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_019_030_002, shared: true)]
class LengthenLongDescr implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'playfields';
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('long_name', 30)->change();
		});
	}
}
