<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\IMPLANT_MODULE;

use function Safe\file;

use Nadybot\Core\Types\Skill;
use Nadybot\Modules\IMPLANT_MODULE\{Cluster, ClusterGrade, Implant};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(Implant::class)]
#[CoversClass(Cluster::class)]
class ImplantTest extends TestCase {
	/** @return list<array{0:float,1:int,2:int}> */
	public static function provideFactorRequirements(): array {
		return [
			// [factor, implant QL, expected]
			// 1h Blunt (1.8) shiny is 2 x 1.8 x QL, like documented in the AO-Universe guide
			[3.6, 100, 360],
			[3.6, 200, 720],
			// Scales linearly above QL 200 for refined implants
			[3.6, 250, 900],
			// Rounds down: Treatment (2.15) bright at QL 50 is 161.25
			[3.225, 50, 161],
			// Rounds half up: Treatment (2.15) bright at QL 20 is 64.5
			[3.225, 20, 65],
			// Rounds up: Sharp Objects (1.25) faded at QL 3 is 3.75
			[1.25, 3, 4],
			// Jobe shiny at QL 250 is 1562.5
			[6.25, 250, 1_563],
			// Nano Delta faded at QL 101 is 277.75
			[2.75, 101, 278],
		];
	}

	#[Test]
	#[DataProvider('provideFactorRequirements')]
	public function requiredSkillIsFactorTimesQL(float $factor, int $ql, int $expected): void {
		$this->assertSame($expected, Implant::getRequiredSkillForFactor($factor, $ql));
	}

	#[Test]
	public function requiredSkillUsesTheFactorOfTheGrade(): void {
		$blunt = new Cluster(
			cluster_id: 2,
			effect_type_id: 1,
			long_name: '1 Handed Blunt Weapons',
			official_name: '1h Blunt Cluster',
			skill: Skill::OneHB,
			tradeskill: Skill::NanoProgramming,
			jobe: false,
			req_faded: 1.8,
			req_bright: 2.7,
			req_shiny: 3.6,
		);
		$this->assertSame(180, Implant::getRequiredSkill($blunt, ClusterGrade::Faded, 100));
		$this->assertSame(270, Implant::getRequiredSkill($blunt, ClusterGrade::Bright, 100));
		$this->assertSame(360, Implant::getRequiredSkill($blunt, ClusterGrade::Shiny, 100));

		$nanoDelta = new Cluster(
			cluster_id: 87,
			effect_type_id: 15,
			long_name: 'Nano Delta*',
			official_name: 'Nano Delta Jobe',
			skill: Skill::NanoDelta,
			tradeskill: Skill::PharmaTech,
			jobe: true,
			req_faded: 2.75,
			req_bright: 4,
			req_shiny: 5.25,
		);
		$this->assertSame(550, Implant::getRequiredSkill($nanoDelta, ClusterGrade::Faded, 200));
		$this->assertSame(800, Implant::getRequiredSkill($nanoDelta, ClusterGrade::Bright, 200));
		$this->assertSame(1_050, Implant::getRequiredSkill($nanoDelta, ClusterGrade::Shiny, 200));
	}

	#[Test]
	public function clusterCsvHasConsistentRequirements(): void {
		foreach (self::readClusterCsv() as $id => $row) {
			if ($row['skill_id'] === '') {
				continue;
			}
			$factors = [(float)$row['req_faded'], (float)$row['req_bright'], (float)$row['req_shiny']];
			if ($row['jobe'] === '1') {
				$expected = ((int)$row['skill_id'] === Skill::NanoDelta->value)
					? [2.75, 4.0, 5.25]
					: [3.25, 4.75, 6.25];
				$this->assertSame($expected, $factors, "Jobe cluster {$id}");
				$this->assertNotSame('', $row['tradeskill_id'], "Jobe cluster {$id}");
				continue;
			}
			$this->assertSame((string)Skill::NanoProgramming->value, $row['tradeskill_id'], "RK cluster {$id}");
			$this->assertEqualsWithDelta($factors[0] * 1.5, $factors[1], 0.000_1, "RK cluster {$id}");
			$this->assertEqualsWithDelta($factors[0] * 2, $factors[2], 0.000_1, "RK cluster {$id}");
			$this->assertGreaterThanOrEqual(1.0, $factors[0], "RK cluster {$id}");
			$this->assertLessThanOrEqual(3.0, $factors[0], "RK cluster {$id}");
		}
	}

	#[Test]
	public function nanoInterruptNeedsPsychology(): void {
		// Verified in-game: a faded QL 200 one needs 650 Psychology
		$row = self::readClusterCsv()[93];
		$this->assertSame((string)Skill::DecNanoInt->value, $row['skill_id']);
		$this->assertSame((string)Skill::Psychology->value, $row['tradeskill_id']);
		$this->assertSame(650, Implant::getRequiredSkillForFactor((float)$row['req_faded'], 200));
	}

	/**
	 * Read the clusters from Cluster.csv
	 *
	 * @return array<int,array<array-key,string>> The CSV rows by cluster_id
	 */
	private static function readClusterCsv(): array {
		$header = null;
		$rows = [];

		/** @var list<string> */
		$lines = file(__DIR__ . '/../../../src/Modules/IMPLANT_MODULE/Cluster.csv', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
		foreach ($lines as $line) {
			if (str_starts_with($line, '#')) {
				continue;
			}
			$fields = array_map(
				static fn (?string $field): string => $field ?? '',
				str_getcsv($line, ',', '"', ''),
			);
			if (!isset($header)) {
				$header = $fields;
				continue;
			}
			$row = array_combine($header, $fields);
			$rows[(int)$row['cluster_id']] = $row;
		}
		return $rows;
	}
}
