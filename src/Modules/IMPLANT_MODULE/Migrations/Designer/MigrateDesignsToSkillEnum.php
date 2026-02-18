<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use function Safe\json_encode;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{Collection, DB, Safe, Types\SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\{Cluster, ImplantDesign};
use Nadylib\Type;
use Psr\Log\LoggerInterface;

use Safe\Exceptions\JsonException;

#[NCA\Migration(order: 2025_02_05_09_13_23, shared: true)]
class MigrateDesignsToSkillEnum implements SchemaMigration {
	/** @var array<string,int> */
	private array $skills = [];

	public function migrate(LoggerInterface $logger, DB $db): void {
		if ($db->schema()->hasTable('cluster_old')) {
			$this->skills = $db->table('cluster_old')
				->getArray()
				->reduce(
					/**
					 * @param array<string,int>     $result
					 * @param array<string,?scalar> $cluster
					 *
					 * @return array<string,int>
					 */
					static function (array $result, array $cluster): array {
						if (isset($cluster['SkillID'], $cluster['LongName'])) {
							$result[(string)$cluster['LongName']] = (int)$cluster['SkillID'];
						}
						return $result;
					},
					[]
				);
		} else {
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
		}

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
					$data = Safe::jsonDecode($design['design'], Type\dict(Type\string(), Type\mixedDict()));
				} catch (JsonException) {
					return;
				}
				foreach ($data as $slot => &$slotConfig) {
					if (isset($slotConfig['symb']) && is_array($slotConfig['symb'])) {

						/** @var array{"Treatment":int,"Level":int,"reqs":list<array{"Name":string,"Amount":int}>,"mods":list<array{"Name":string,"Amount":int}>} */
						$symb = Type\shape([
							'reqs' => Type\vec(Type\shape([
								'Name' => Type\string(),
								'Amount' => Type\int(),
							], true)),
							'mods' => Type\vec(Type\shape([
								'Name' => Type\string(),
								'Amount' => Type\int(),
							], true)),
							'Treatment' => Type\int(),
							'Level' => Type\int(),
						])->coerce($slotConfig['symb']);

						$slotConfig['symb']['reqs'] = array_map($this->convertSkills(...), $symb['reqs']);
						$slotConfig['symb']['mods'] = array_map($this->convertSkills(...), $symb['mods']);
						$slotConfig['symb']['treatment'] = $slotConfig['symb']['Treatment'];
						$slotConfig['symb']['level'] = $slotConfig['symb']['Level'];
						unset($slotConfig['symb']['Treatment']);
						unset($slotConfig['symb']['Level']);
					} else {
						foreach (['shiny', 'bright', 'faded'] as $grade) {
							if (array_key_exists($grade, $slotConfig) && is_string($slotConfig[$grade])) {
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
	 * @param array{"Name":string,"Amount":int} $skillAmount
	 *
	 * @return array{"skill":int,"amount":int}
	 */
	private function convertSkills(array $skillAmount): array {
		return [
			'skill' => $this->skills[$skillAmount['Name']],
			'amount' => $skillAmount['Amount'],
		];
	}
}
