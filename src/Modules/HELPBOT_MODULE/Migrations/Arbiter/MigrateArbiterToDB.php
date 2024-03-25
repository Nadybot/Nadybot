<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Arbiter;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	SchemaMigration,
};
use Nadybot\Modules\HELPBOT_MODULE\ArbiterController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_21_06_32_53, shared: true)]
class MigrateArbiterToDB implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ArbiterController::DB_TABLE;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('type', 3)->unique();
			$table->unsignedInteger('start')->index();
			$table->unsignedInteger('end')->index();
		});
		$db->table($table)->insert([
			['type' => ArbiterController::AI,  'start' => 1_618_704_000, 'end' => 1_619_395_200],
			['type' => ArbiterController::BS,  'start' => 1_619_913_600, 'end' => 1_620_604_800],
			['type' => ArbiterController::DIO, 'start' => 1_621_123_200, 'end' => 1_621_814_400],
		]);
	}
}
