<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\SKILLS_MODULE\PerkLevelAction;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_12_55_05, shared: true)]
class CreatePerkLevelActionsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PerkLevelAction::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->uuid('perk_level_id')->index();
			$table->integer('action_id');
			$table->boolean('scaling')->default(false);
		});
	}
}
