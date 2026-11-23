<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\ITEMS_MODULE;

use Nadybot\Modules\ITEMS_MODULE\{AODBEntry, ItemBuff, ItemGroup, ItemGroupName, ItemType, ItemsController};
use Nadybot\Modules\ITEMS_MODULE\Exceptions\ItemImportException;
use Nadybot\Modules\ITEMS_MODULE\Import\{ItemImportResult, ItemImporter, ItemRowSink};
use Nadybot\Modules\SKILLS_MODULE\WeaponAttribute;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemImporter::class)]
class ItemImporterTest extends TestCase {
	/** @var array<string,list<array<string,null|int|string>>> */
	private array $rows = [];

	#[Test]
	public function createsItemsFromFamiliesAndTemplates(): void {
		$result = $this->import([
			'version' => '1.0',
			'families' => [
				['items' => [[[10, 1], [11, 100]], [[11, 101], [12, 200]]]],
				['name' => 'Explicit Name', 'items' => [[[20, 5]]]],
			],
			'templates' => [
				'10' => ['ql' => 1, 'name' => 'Basic Pistol', 'stats' => ['0' => 7, '30' => 5, '76' => 1, '79' => 1_000, '298' => 8]],
				'11' => ['ql' => 100, 'name' => 'Good Pistol', 'froob_friendly' => false],
				'12' => ['ql' => 200, 'name' => 'Superior Pistol'],
				'20' => ['ql' => 5, 'name' => 'Something'],
				'30' => ['ql' => 50, 'name' => 'Lonely Item', 'stats' => ['76' => 2]],
			],
		], [12 => true]);

		$this->assertSame(3, $result->familyItems);
		$this->assertSame(1, $result->directItems);
		$this->assertSame(1, $result->notInGame);
		$this->assertSame([
			['lowid' => 10, 'highid' => 11, 'lowql' => 1, 'highql' => 100, 'name' => 'Basic Pistol',
			 'icon' => 1_000, 'froob_friendly' => 0, 'slot' => 8, 'flags' => 7, 'in_game' => 1, 'type' => 'Weapon', 'properties' => 5],
			['lowid' => 11, 'highid' => 12, 'lowql' => 101, 'highql' => 200, 'name' => 'Good Pistol',
			 'icon' => 0, 'froob_friendly' => 0, 'slot' => 0, 'flags' => 0, 'in_game' => 0, 'type' => 'Misc', 'properties' => 0],
			['lowid' => 20, 'highid' => 20, 'lowql' => 5, 'highql' => 5, 'name' => 'Something',
			 'icon' => 0, 'froob_friendly' => 1, 'slot' => 0, 'flags' => 0, 'in_game' => 1, 'type' => 'Misc', 'properties' => 0],
			['lowid' => 30, 'highid' => 30, 'lowql' => 50, 'highql' => 50, 'name' => 'Lonely Item',
			 'icon' => 0, 'froob_friendly' => 1, 'slot' => 0, 'flags' => 0, 'in_game' => 1, 'type' => 'Armor', 'properties' => 0],
		], $this->rows[AODBEntry::class]);
		$this->assertSame([
			['id' => 1, 'group_id' => 1, 'item_id' => 10],
			['id' => 2, 'group_id' => 1, 'item_id' => 11],
			['id' => 3, 'group_id' => 1, 'item_id' => 12],
			['id' => 4, 'group_id' => 2, 'item_id' => 20],
		], $this->rows[ItemGroup::class]);
		$this->assertSame([
			['group_id' => 1, 'name' => 'Pistol'],
			['group_id' => 2, 'name' => 'Explicit Name'],
		], $this->rows[ItemGroupName::class]);
	}

	#[Test]
	public function importsBuffsTypesAndWeaponsOfAllTemplates(): void {
		$this->import([
			'version' => '1',
			'templates' => [
				'1' => [
					'ql' => 1, 'name' => 'Gun', 'types' => ['Weapon', 'Pistol'], 'buffs' => ['17' => 5, '18' => [7, -3]],
					'stats' => ['30' => 4_096 | 33_554_432, '294' => 100, '210' => 200, '374' => 0, '134' => 1_300],
				],
				'2' => ['ql' => 10, 'name' => 'Head Implant: Jobe, Shiny', 'buffs' => ['20' => 1], 'types' => ['Head']],
			],
		], []);

		$this->assertSame([
			['item_id' => 1, 'attribute_id' => 17, 'amount' => 5],
			['item_id' => 1, 'attribute_id' => 18, 'amount' => 7],
			['item_id' => 2, 'attribute_id' => 20, 'amount' => 1],
		], $this->rows[ItemBuff::class]);
		$this->assertSame([
			['item_id' => 1, 'item_type' => 'Weapon'],
			['item_id' => 1, 'item_type' => 'Pistol'],
			['item_id' => 2, 'item_type' => 'Head'],
		], $this->rows[ItemType::class]);
		$this->assertSame([[
			'id' => 1, 'attack_time' => 100, 'recharge_time' => 200, 'full_auto' => null, 'burst' => 0,
			'fling_shot' => 1, 'fast_attack' => 0, 'aimed_shot' => 0, 'brawl' => 1, 'sneak_attack' => 0,
			'multi_m' => null, 'multi_r' => 1_300,
		]], $this->rows[WeaponAttribute::class]);
		$this->assertCount(1, $this->rows[AODBEntry::class], 'The filtered implant must not become an item');
	}

	#[Test]
	public function filtersTemplatesByName(): void {
		$result = $this->import([
			'version' => '1',
			'templates' => [
				'1' => ['ql' => 1, 'name' => 'Eye Implant: Sharp Obj, Faded'],
				'2' => ['ql' => 1, 'name' => 'Blister Pack: Nano Charge'],
				'3' => ['ql' => 1, 'name' => 'Pill with Nano Charge'],
				'4' => ['ql' => 1, 'name' => 'DefaultMelee_001'],
				'5' => ['ql' => 1, 'name' => 'Basic Eye Implant'],
				'6' => ['ql' => 1, 'name' => 'Charged Nano Critter'],
			],
		], []);
		$this->assertSame(4, $result->filteredTemplates);
		$this->assertSame([5, 6], array_column($this->rows[AODBEntry::class], 'lowid'));
	}

	#[Test]
	public function skipsInvalidItems(): void {
		$result = $this->import([
			'version' => '1',
			'families' => [
				['items' => [
					[[1, 1], [2, 100]],
					[[1, 100], [2, 1]],
					[[1, 0]],
					[[1, 1], [2, 2], [3, 3]],
					[[1, 'x']],
					[[1, 1], [99, 100]],
				]],
			],
			'templates' => [
				'1' => ['ql' => 1, 'name' => 'A'],
				'2' => ['ql' => 100, 'name' => 'B'],
				'3' => ['name' => 'Without QL'],
			],
		], []);
		$this->assertSame(1, $result->familyItems);
		$this->assertSame([
			'item with low QL above high QL' => 1,
			'item with a QL out of range' => 1,
			'item without 1 or 2 steps' => 1,
			'item with an invalid step' => 1,
			'item with an unknown template' => 1,
			'template without QL' => 1,
		], $result->skipped);
	}

	#[Test]
	public function templateOrderDoesNotMatter(): void {
		$this->import([
			'templates' => [
				'1' => ['ql' => 1, 'name' => 'Low'],
				'2' => ['ql' => 200, 'name' => 'High'],
			],
			'families' => [['items' => [[[1, 1], [2, 200]]]]],
			'version' => '1',
		], []);
		$this->assertCount(1, $this->rows[AODBEntry::class]);
	}

	#[Test]
	public function failsWithoutVersion(): void {
		$this->expectException(ItemImportException::class);
		$this->import(['templates' => ['1' => ['ql' => 1, 'name' => 'A']]], []);
	}

	#[Test]
	public function failsWithoutItems(): void {
		$this->expectException(ItemImportException::class);
		$this->import(['version' => '1', 'templates' => ['1' => ['ql' => 1, 'name' => 'Eye Implant: X, Faded']]], []);
	}

	#[Test]
	public function failsOnInvalidJson(): void {
		$this->expectException(ItemImportException::class);
		$this->importRaw('{"version":"1","templates":{"1":{"ql":1,,}}}');
	}

	/**
	 * @param array<string,mixed> $document
	 * @param array<int,true>     $notInGame
	 */
	private function import(array $document, array $notInGame): ItemImportResult {
		return $this->importRaw(json_encode($document, \JSON_THROW_ON_ERROR), $notInGame);
	}

	/** @param array<int,true> $notInGame */
	private function importRaw(string $json, array $notInGame=[]): ItemImportResult {
		$this->rows = [];
		$sink = new class ($this->rows) implements ItemRowSink {
			/** @param array<string,list<array<string,null|int|string>>> $rows */
			public function __construct(private array &$rows) {
			}

			public function add(string $table, array $row): void {
				$this->rows[$table] ??= [];
				$this->rows[$table] []= $row;
			}

			public function flush(): void {
			}
		};
		$importer = new ItemImporter($sink, (new ItemsController())->getLongestCommonStringOfWords(...));
		return $importer->import(str_split($json, 5), $notInGame);
	}
}
