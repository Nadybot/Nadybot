<?php declare(strict_types=1);

namespace Nadybot\Tests\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\{
	DirectoryFeature,
	DirectoryProvider,
	DirectoryRegistry,
};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(DirectoryRegistry::class)]
class DirectoryRegistryTest extends TestCase {
	#[Test]
	public function supportsOnlyWhatProvidersOffer(): void {
		$registry = $this->registry();
		$this->assertTrue($registry->supports(DirectoryFeature::Character, 5));
		$this->assertTrue($registry->supports(DirectoryFeature::Character, 7));
		$this->assertTrue($registry->supports(DirectoryFeature::OrgRoster, 7));
		$this->assertFalse($registry->supports(DirectoryFeature::OrgList, 7));
		$this->assertFalse($registry->supports(DirectoryFeature::History, 7));
		$this->assertFalse($registry->supports(DirectoryFeature::Character, 8));
	}

	#[Test]
	public function featuresAreConfiguredPerInstance(): void {
		$registry = $this->registry();
		// "charsonly" implements the roster interface, but doesn't offer it
		$ids = array_map(
			static fn (DirectoryProvider $p): string => $p->getId(),
			$registry->orgRosterProviders(5)
		);
		$this->assertSame(['bork', 'pork'], $ids);
	}

	#[Test]
	public function preferredProviderComesFirst(): void {
		$registry = $this->registry();
		$ids = static fn (array $providers): array => array_map(static fn (DirectoryProvider $p): string => $p->getId(), $providers);
		$this->assertSame(['bork', 'pork', 'charsonly'], $ids($registry->characterProviders(5)));
		$this->assertSame(['pork', 'bork', 'charsonly'], $ids($registry->characterProviders(5, 'pork')));
		$this->assertSame(['charsonly', 'bork', 'pork'], $ids($registry->characterProviders(5, 'charsonly')));
		// A preferred provider that doesn't serve the dimension changes nothing
		$this->assertSame(['lostEden'], $ids($registry->characterProviders(7, 'pork')));
	}

	#[Test]
	public function settingOptionsOnlyContainMatchingProviders(): void {
		$registry = $this->registry();
		$this->assertSame(
			['BORK' => 'bork', 'PORK' => 'pork', 'Chars only' => 'charsonly'],
			$registry->getSettingOptions(DirectoryFeature::Character, [4, 5, 6])
		);
		$this->assertSame(
			['BORK' => 'bork', 'PORK' => 'pork'],
			$registry->getSettingOptions(DirectoryFeature::OrgRoster, [4, 5, 6])
		);
	}

	private function registry(): DirectoryRegistry {
		$all = [DirectoryFeature::Character, DirectoryFeature::OrgRoster];
		return new DirectoryRegistry([
			new FakeProvider('bork', 'BORK', [4, 5, 6], $all),
			new FakeProvider('pork', 'PORK', [4, 5, 6], $all),
			new FakeProvider('charsonly', 'Chars only', [5], [DirectoryFeature::Character]),
			new FakeProvider('lostEden', 'Lost Eden', [7], $all),
		]);
	}
}
