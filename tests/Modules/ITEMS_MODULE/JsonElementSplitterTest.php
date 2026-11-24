<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\ITEMS_MODULE;

use Nadybot\Modules\ITEMS_MODULE\Exceptions\ItemImportException;
use Nadybot\Modules\ITEMS_MODULE\Import\JsonElementSplitter;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonElementSplitter::class)]
class JsonElementSplitterTest extends TestCase {
	private const DOCUMENT = <<<'JSON'
		{"version":"1.2.3",
		 "families" : [
		  {"name":"A \"quoted\" [name] {x}","items":[[[1,1],[2,200]]]},
		  {"items":[[[3,5]]]}
		 ],
		 "count": 42,
		 "templates":{
		  "1":{"ql":1,"name":"Back\\slash ä","stats":{"79":5}},
		  "2" : {"ql":200,"name":"}]","buffs":{"1":[1,2]}}
		 },
		 "empty":[],
		 "nothing":{},
		 "flag":true
		}
		JSON;

	/** @return list<array{int<1,max>}> */
	public static function chunkSizes(): array {
		return [[1], [2], [3], [7], [64], [100_000]];
	}

	/** @param int<1,max> $chunkSize */
	#[Test]
	#[DataProvider('chunkSizes')]
	public function splitsIntoElementsRegardlessOfChunkSize(int $chunkSize): void {
		$elements = $this->split(self::DOCUMENT, $chunkSize);
		$this->assertSame([
			['version', null, '"1.2.3"'],
			['families', null, '{"name":"A \"quoted\" [name] {x}","items":[[[1,1],[2,200]]]}'],
			['families', null, '{"items":[[[3,5]]]}'],
			['count', null, '42'],
			['templates', '1', '{"ql":1,"name":"Back\\\\slash ä","stats":{"79":5}}'],
			['templates', '2', '{"ql":200,"name":"}]","buffs":{"1":[1,2]}}'],
			['flag', null, 'true'],
		], $elements);
		foreach ($elements as [, , $raw]) {
			$this->assertJson($raw);
		}
	}

	#[Test]
	public function splitsScalarArrayElements(): void {
		$this->assertSame([
			['list', null, '1'],
			['list', null, '"a,b]"'],
			['list', null, 'null'],
		], $this->split('{"list":[1, "a,b]" ,null]}', 3));
	}

	#[Test]
	public function failsOnTruncatedDocument(): void {
		$this->expectException(ItemImportException::class);
		$this->split(substr(self::DOCUMENT, 0, 120), 10);
	}

	#[Test]
	public function failsOnDocumentThatIsNoObject(): void {
		$this->expectException(ItemImportException::class);
		$this->split('[1,2,3]', 10);
	}

	#[Test]
	public function failsOnMissingColon(): void {
		$this->expectException(ItemImportException::class);
		$this->split('{"version" "1"}', 10);
	}

	#[Test]
	public function failsOnDataAfterTheEnd(): void {
		$this->expectException(ItemImportException::class);
		$this->split('{"version":"1"} {', 10);
	}

	/**
	 * @param int<1,max> $chunkSize
	 *
	 * @return list<array{string,?string,string}>
	 */
	private function split(string $document, int $chunkSize): array {
		$splitter = new JsonElementSplitter();
		$result = [];
		foreach (str_split($document, $chunkSize) as $chunk) {
			foreach ($splitter->feed($chunk) as $element) {
				$result []= $element;
			}
		}
		$splitter->finish();
		return $result;
	}
}
