<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\ITEMS_MODULE;

use Nadybot\Modules\ITEMS_MODULE\Import\{FamilyStore, IdSet, ItemTemplate, TemplateStore};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(IdSet::class)]
#[CoversClass(TemplateStore::class)]
#[CoversClass(FamilyStore::class)]
class ImportStoresTest extends TestCase {
	#[Test]
	public function idSetStoresIdsInAndOutsideTheBitField(): void {
		$set = new IdSet();
		$ids = [0, 1, 7, 8, 9, 246_710, 5_000_015, 1 << 26, \PHP_INT_MAX, -5];
		foreach ($ids as $id) {
			$set->add($id);
		}
		foreach ($ids as $id) {
			$this->assertTrue($set->has($id), "{$id} is missing");
		}
		foreach ([2, 6, 10, 246_711, 5_000_014, (1 << 26) - 1, (1 << 26) + 1, -4, 99_999_999] as $id) {
			$this->assertFalse($set->has($id), "{$id} must not be in the set");
		}
	}

	#[Test]
	public function templateStoreReturnsWhatWasAdded(): void {
		$store = new TemplateStore();
		$withQL = new ItemTemplate(
			ql: 200,
			name: 'Augmented Nano Armor Sleeves ÄÖÜ',
			icon: 13_231,
			slot: 640,
			flags: -1_073_741_821,
			properties: 5,
			itemClass: 2,
			froobFriendly: false,
		);
		$withoutQL = new ItemTemplate(
			ql: null,
			name: '',
			icon: 0,
			slot: 0,
			flags: \PHP_INT_MIN + 1,
			properties: \PHP_INT_MAX,
			itemClass: 0,
			froobFriendly: true,
		);
		$store->add(21_793, $withQL);
		$store->add(5, $withoutQL);
		$this->assertEquals($withQL, $store->get(21_793));
		$this->assertEquals($withoutQL, $store->get(5));
		$this->assertNull($store->get(6));
		$this->assertSame([21_793, 5], iterator_to_array($store->aoids(), false));
	}

	#[Test]
	public function familyStoreReturnsFamiliesInOrder(): void {
		$store = new FamilyStore();
		$store->add(null, [[246_710, 100, 246_711, 102], [246_712, 103, 246_713, 104]]);
		$store->add('Blood-Stained Mace', [[165_125, 1, 165_126, 199], [165_127, 200, 165_127, 200]]);
		$store->add(null, [[5_000_015, 1, 5_000_015, 1]]);
		$this->assertSame([
			[null, [[246_710, 100, 246_711, 102], [246_712, 103, 246_713, 104]]],
			['Blood-Stained Mace', [[165_125, 1, 165_126, 199], [165_127, 200, 165_127, 200]]],
			[null, [[5_000_015, 1, 5_000_015, 1]]],
		], iterator_to_array($store->families(), false));
	}
}
