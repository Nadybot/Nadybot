<?php declare(strict_types=1);

namespace Nadybot\Tests\Core;

use Nadybot\Core\Types\Dimension;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(Dimension::class)]
class DimensionTest extends TestCase {
	#[Test]
	public function itNamesDimensions(): void {
		$this->assertSame('Test', Dimension::nameOf(4));
		$this->assertSame('RK5', Dimension::nameOf(5));
		$this->assertSame('RK19', Dimension::nameOf(6));
		$this->assertSame('Lost Eden', Dimension::nameOf(7));
		$this->assertSame('RK1', Dimension::nameOf(1));
		$this->assertSame('RK2', Dimension::nameOf(2));
	}

	#[Test]
	public function itHasNoSpacesInRelaySuffixes(): void {
		foreach (Dimension::cases() as $dimension) {
			$this->assertStringNotContainsString(' ', $dimension->getRelaySuffix());
		}
		$this->assertSame('RK19', Dimension::RK19->getRelaySuffix());
		$this->assertSame('LostEden', Dimension::LostEden->getRelaySuffix());
	}

	#[Test]
	public function itKnowsFuncomDimensions(): void {
		$funcom = array_values(array_map(
			static fn (Dimension $d): int => $d->value,
			array_filter(Dimension::cases(), static fn (Dimension $d): bool => $d->isFuncom())
		));
		$this->assertSame(Dimension::FUNCOM_DIMENSIONS, $funcom);
		$this->assertFalse(Dimension::isFuncomDimension(7));
		$this->assertFalse(Dimension::isFuncomDimension(2));
		$this->assertTrue(Dimension::isFuncomDimension(5));
	}
}
