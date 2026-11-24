<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\ITEMS_MODULE;

use Nadybot\Modules\ITEMS_MODULE\{AODBEntry, AODBItem, AodbType, ItemSearchResult, ItemWithBuffs};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(AODBEntry::class)]
#[CoversClass(AODBItem::class)]
class AODBEntryTest extends TestCase {
	/** @return list<array{int,string}> */
	public static function nanoArmorSleeves(): array {
		return [
			[1, 'Basic Nano Armor Sleeves'],
			[100, 'Basic Nano Armor Sleeves'],
			[101, 'Augmented Nano Armor Sleeves'],
			[199, 'Augmented Nano Armor Sleeves'],
			[0, 'Basic Nano Armor Sleeves'],
			[200, 'Basic Nano Armor Sleeves'],
		];
	}

	#[Test]
	#[DataProvider('nanoArmorSleeves')]
	public function closerReferenceQLWins(int $ql, string $expected): void {
		$item = self::entry(85_738, 21_793, 1, 199, 'Basic Nano Armor Sleeves', 'Augmented Nano Armor Sleeves', 1, 200);
		$this->assertSame($expected, $item->getNameAtQL($ql));
	}

	#[Test]
	public function highNameWinsOnATie(): void {
		$item = self::entry(1, 2, 20, 40, 'Low', 'High', 20, 40);
		$this->assertSame('Low', $item->getNameAtQL(29));
		$this->assertSame('High', $item->getNameAtQL(30));
	}

	#[Test]
	public function invertedReferenceQLs(): void {
		$item = self::entry(304_835, 305_924, 1, 250, 'Low', 'High', 250, 1);
		$this->assertSame('High', $item->getNameAtQL(1));
		$this->assertSame('High', $item->getNameAtQL(125));
		$this->assertSame('Low', $item->getNameAtQL(126));
		$this->assertSame('Low', $item->getNameAtQL(250));
	}

	#[Test]
	public function sameNamesDoNotChange(): void {
		$item = self::entry(291_082, 291_083, 1, 99, 'Health and Nano Recharger', 'Health and Nano Recharger', 1, 100);
		foreach ([1, 50, 51, 99] as $ql) {
			$this->assertSame('Health and Nano Recharger', $item->getNameAtQL($ql));
		}
	}

	#[Test]
	public function withoutReferenceDataTheBaseNameIsUsed(): void {
		$item = self::entry(1, 2, 1, 200, 'Low', null, null, null);
		$this->assertSame('Low', $item->getNameAtQL(200));
		$item = self::entry(1, 2, 1, 200, 'Low', 'High', 1, null);
		$this->assertSame('Low', $item->getNameAtQL(200));
	}

	#[Test]
	public function getNameIsIndependentOfTheQL(): void {
		$item = self::entry(85_738, 21_793, 1, 199, 'Basic Nano Armor Sleeves', 'Augmented Nano Armor Sleeves', 1, 200);
		$this->assertSame('Basic Nano Armor Sleeves', $item->getName());
	}

	#[Test]
	public function linksUseTheNameAtTheirQL(): void {
		$item = self::entry(85_738, 21_793, 1, 199, 'Basic Nano Armor Sleeves', 'Augmented Nano Armor Sleeves', 1, 200);
		$this->assertSame(
			"<a href='itemref://85738/21793/150'>Augmented Nano Armor Sleeves</a>",
			$item->getLink(150)
		);
		$this->assertSame(
			"<a href='itemref://85738/21793/1'>Basic Nano Armor Sleeves</a>",
			$item->getLink()
		);
		$this->assertSame("<a href='itemref://85738/21793/150'>150</a>", $item->getLink(150, '150'));
		$this->assertSame("<a href='itemref://85738/21793/150'></a>", $item->getLink(150, ''));
	}

	#[Test]
	public function itemsAtAQLKeepTheReferenceData(): void {
		$item = self::entry(85_738, 21_793, 1, 199, 'Basic Nano Armor Sleeves', 'Augmented Nano Armor Sleeves', 1, 200);
		$atQL = $item->atQL(150);
		$this->assertSame("<a href='itemref://85738/21793/150'>Augmented Nano Armor Sleeves</a>", $atQL->getLink());
		$atQL->setQL(50);
		$this->assertSame("<a href='itemref://85738/21793/50'>Basic Nano Armor Sleeves</a>", $atQL->getLink());

		$searchResult = ItemSearchResult::fromItem($item->atQL(150));
		$this->assertSame('Augmented Nano Armor Sleeves', $searchResult->getNameAtQL(150));

		$withBuffs = ItemWithBuffs::fromEntry($item);
		$this->assertSame('Augmented Nano Armor Sleeves', $withBuffs->getNameAtQL(150));
	}

	private static function entry(
		int $lowId,
		int $highId,
		int $lowQL,
		int $highQL,
		string $name,
		?string $highName,
		?int $lowRefQL,
		?int $highRefQL,
	): AODBEntry {
		return new AODBEntry(
			lowid: $lowId,
			highid: $highId,
			lowql: $lowQL,
			highql: $highQL,
			name: $name,
			icon: 0,
			slot: 0,
			flags: 0,
			in_game: true,
			type: AodbType::Armor,
			froob_friendly: true,
			properties: 0,
			high_name: $highName,
			low_ref_ql: $lowRefQL,
			high_ref_ql: $highRefQL,
		);
	}
}
