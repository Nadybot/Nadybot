<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use function Safe\{json_decode, json_encode};

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{Collection, DB, Types\SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\{Cluster, ImplantDesign};
use Psr\Log\LoggerInterface;

use Safe\Exceptions\JsonException;

#[NCA\Migration(order: 2025_02_05_09_13_23, shared: true)]
class MigrateDesignsToSkillEnum implements SchemaMigration {
	/** @var array<string,int> */
	private array $skills = [];

	public function migrate(LoggerInterface $logger, DB $db): void {
		$this->skills = $db->table(Cluster::getTable())
			->asObj(Cluster::class)
			->reduce(
				/**
				 * @param array<string,int> $result
				 *
				 * @return array<string,int>
				 */
				static function (array $result, Cluster $cluster): array {
					if (isset($cluster->skill)) {
						$result[$cluster->long_name] = $cluster->skill->value;
					}
					return $result;
				},
				[]
			);

		/**
		 * @var Collection<int,array{"design"?:string,"name"?:string,"owner"?:string}>
		 *
		 * @phpstan-ignore varTag.type
		 */
		$data = $db->table(ImplantDesign::getTable())->getArray();

		$data->each(
			/** @param array{"design"?:string,"name"?:string,"owner"?:string} $design */
			function (array $design) use ($db): void {
				if (!isset($design['design'])) {
					return;
				}
				try {
					$data = json_decode($design['design'], true);
					if (!is_array($data)) {
						return;
					}
				} catch (JsonException) {
					return;
				}
				foreach ($data as $slot => &$slotConfig) {
					if (!is_array($slotConfig)) {
						continue;
					}
					if (isset($slotConfig['symb']) && is_array($slotConfig['symb'])) {
						/** @var array{"reqs":list<array{"name":string,"amount":int}>,"mods":list<array{"name":string,"amount":int}>} */
						$symb = $slotConfig['symb'];

						$slotConfig['symb']['reqs'] = array_map($this->convertSkills(...), $symb['reqs']);
						$slotConfig['symb']['mods'] = array_map($this->convertSkills(...), $symb['mods']);
					} else {
						foreach (['shiny', 'bright', 'faded'] as $grade) {
							if (isset($slotConfig[$grade]) && is_string($slotConfig[$grade])) {
								$slotConfig[$grade] = $this->skills[$slotConfig[$grade]] ?? null;
							}
						}
					}
				}
				$db->table(ImplantDesign::getTable())
					->where('name', $design['name'] ?? '@')
					->where('owner', $design['owner'] ?? '')
					->update(['design' => json_encode($data)]);
			}
		);
	}

	/**
	 * @param array{"name":string,"amount":int} $skillAmount
	 *
	 * @return array{"skill":int,"amount":int}
	 */
	private function convertSkills(array $skillAmount): array {
		return [
			'skill' => $this->skills[$skillAmount['name']],
			'amount' => $skillAmount['amount'],
		];
	}
}
