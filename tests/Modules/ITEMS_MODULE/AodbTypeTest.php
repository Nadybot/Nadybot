<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\ITEMS_MODULE;

use Nadybot\Modules\ITEMS_MODULE\AodbType;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(AodbType::class)]
class AodbTypeTest extends TestCase {
	/** @return list<array{int,string,AodbType}> */
	public static function templates(): array {
		return [
			[1, 'Nano Crystal (Fake Weapon)', AodbType::Weapon],
			[2, 'Instruction Disc (Fake Armor)', AodbType::Armor],
			[3, 'Basic Head Implant', AodbType::Implant],
			[5, 'Spirit of Fake', AodbType::Spirit],
			[0, 'Nano Crystal (Chemical Concoction)', AodbType::Crystal],
			[0, "NanoCrystal (A Sergeant's Knowledge)", AodbType::Crystal],
			[0, 'Premium Nano Crystal (Enter the Grid)', AodbType::Crystal],
			[0, 'Weird Looking Nano Crystal (Smoke Bomb)', AodbType::Crystal],
			[0, "Enforcer: Startup Crystal - Thug's Delight", AodbType::Crystal],
			[0, 'Startup Crystal: Nano Crystal (Weak Health Funnel)', AodbType::Crystal],
			[0, 'Alien Matrix Alpha Box', AodbType::Crystal],
			[0, 'Instruction Disc (Calling of Altumus)', AodbType::Disc],
			[0, 'Robot Instruction Disc (Kamikaze Robot I)', AodbType::Disc],
			[0, 'Robot Compiled Algorithm (Kamikaze Robot I)', AodbType::Disc],
			[0, 'Programmed Photon Particle Emitter (Improve Crushing Weapons)', AodbType::Disc],
			[0, 'Tainted Shadow Crystal (Bad Blood)', AodbType::Tainted],
			[0, 'Overcharged Corroded Nano Crystal (Draw Attention)', AodbType::Tainted],
			[0, 'Corroded Crystal with Bullet Holes (Draw Attention)', AodbType::Tainted],
			[0, 'Shattered Instruction Disc', AodbType::Misc],
			[0, 'Empty Instruction Disc', AodbType::Misc],
			[0, 'Cracked Crystal (Something)', AodbType::Misc],
			[0, 'Adapted Notum Crystal - Creation I', AodbType::Misc],
			[0, 'Alien Data Storage Crystal - Alpha', AodbType::Misc],
			[4, 'Nano Crystal (Unknown Item Class)', AodbType::Crystal],
		];
	}

	#[Test]
	#[DataProvider('templates')]
	public function derivesTypeFromItemClassAndName(int $itemClass, string $name, AodbType $expected): void {
		$this->assertSame($expected, AodbType::fromTemplate($itemClass, $name));
	}
}
