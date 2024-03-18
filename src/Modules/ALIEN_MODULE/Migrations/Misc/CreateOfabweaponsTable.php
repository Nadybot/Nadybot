<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210425131654, shared: true)]
class CreateOfabweaponsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "ofabweapons";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("type")->default(0);
			$table->string("name", 255)->default('');
		});
	}
}
