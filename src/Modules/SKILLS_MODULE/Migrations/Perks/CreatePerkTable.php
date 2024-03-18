<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427142233)]
class CreatePerkTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "perk";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("name", 30)->index();
			$table->string("expansion", 2);
			$table->text("description")->nullable();
		});
	}
}
