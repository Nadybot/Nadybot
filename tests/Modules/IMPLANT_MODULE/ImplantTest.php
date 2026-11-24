<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\IMPLANT_MODULE;

use InvalidArgumentException;
use Nadybot\Modules\IMPLANT_MODULE\{Cluster, ClusterGrade, Implant};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(Implant::class)]
class ImplantTest extends TestCase {
	/**
	 * @return list<array{0:int,1:ClusterGrade,2:int,3:int}>
	 */
	public static function provideRkNpRequirements(): array {
		return [
			// [np_req, grade, implant QL, expected]
			// Shiny is 2 x multiplier x QL, like documented in the AO-Universe guide
			[720, ClusterGrade::Shiny, 100, 360],
			// np_req is the shiny requirement at QL 200
			[720, ClusterGrade::Shiny, 200, 720],
			// Bright is 1.5 x multiplier x QL
			[720, ClusterGrade::Bright, 100, 270],
			// Faded is 1 x multiplier x QL
			[720, ClusterGrade::Faded, 100, 180],
			// Scales linearly above QL 200 for refined implants
			[720, ClusterGrade::Shiny, 250, 900],
			// Floors non-integer results: Treatment (2.15) bright at QL 50
			[860, ClusterGrade::Bright, 50, 161],
			// Floors non-integer results: Sharp Objects (1.25) bright at QL 101
			[500, ClusterGrade::Bright, 101, 189],
			// The lowest multiplier is 1.0: Heavy Weapons
			[400, ClusterGrade::Shiny, 250, 500],
		];
	}

	/**
	 * @return list<array{0:ClusterGrade,1:int,2:int}>
	 */
	public static function provideJobeNpRequirements(): array {
		return [
			[ClusterGrade::Shiny, 250, 1562],
			[ClusterGrade::Bright, 250, 1187],
			[ClusterGrade::Faded, 250, 812],
			[ClusterGrade::Shiny, 300, 1875],
		];
	}

	#[Test]
	#[DataProvider('provideRkNpRequirements')]
	public function getRkNpRequirementFromNpReq(int $npReq, ClusterGrade $grade, int $ql, int $expected): void {
		$this->assertSame($expected, Implant::getRequiredNPForNpReq($npReq, $grade, $ql));
	}

	#[Test]
	public function rkNpRequirementPerCluster(): void {
		$blunt = new Cluster(2, 1, '1 Handed Blunt Weapons', '1h Blunt', 720);
		$this->assertSame(360, Implant::getRequiredNP(false, ClusterGrade::Shiny, 100, $blunt));
		$this->assertSame(180, Implant::getRequiredNP(false, ClusterGrade::Faded, 100, $blunt));
		$this->assertSame(720, Implant::getRequiredNP(false, ClusterGrade::Shiny, 200, $blunt));
	}

	#[Test]
	#[DataProvider('provideJobeNpRequirements')]
	public function getJobeNpRequirementPerGrade(ClusterGrade $grade, int $ql, int $expected): void {
		$this->assertSame($expected, Implant::getRequiredNP(true, $grade, $ql));
	}

	#[Test]
	public function rkNpRequirementNeedsTheCluster(): void {
		$this->expectException(InvalidArgumentException::class);
		Implant::getRequiredNP(false, ClusterGrade::Shiny, 100);
	}
}
