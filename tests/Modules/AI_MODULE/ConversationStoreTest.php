<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\AI_MODULE;

use Nadybot\Modules\AI_MODULE\ConversationStore;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationStore::class)]
class ConversationStoreTest extends TestCase {
	#[Test]
	public function hasReturnsFalseForMissingKey(): void {
		$store = new ConversationStore();
		$this->assertFalse($store->has('missing'));
	}

	#[Test]
	public function getOrCreateReturnsConversation(): void {
		$store = new ConversationStore();
		$conversation = $store->getOrCreate('pub');
		$this->assertTrue($store->has('pub'));
		$this->assertSame($conversation, $store->get('pub'));
	}

	#[Test]
	public function getOrCreateReturnsExistingInstance(): void {
		$store = new ConversationStore();
		$first = $store->getOrCreate('pub');
		$second = $store->getOrCreate('pub');
		$this->assertSame($first, $second);
	}

	#[Test]
	public function getThrowsForMissingKey(): void {
		$store = new ConversationStore();
		$this->expectException(\InvalidArgumentException::class);
		$store->get('missing');
	}

	#[Test]
	public function removeDeletesConversation(): void {
		$store = new ConversationStore();
		$store->getOrCreate('pub');
		$this->assertTrue($store->has('pub'));
		$store->remove('pub');
		$this->assertFalse($store->has('pub'));
	}

	#[Test]
	public function getKeysReturnsEmptyArrayForFreshStore(): void {
		$store = new ConversationStore();
		$this->assertSame([], $store->getKeys());
	}

	#[Test]
	public function getKeysReturnsAllKeys(): void {
		$store = new ConversationStore();
		$store->getOrCreate('pub');
		$store->getOrCreate('org');
		$this->assertSame(['pub', 'org'], $store->getKeys());
	}

	#[Test]
	public function removeOnMissingKeyDoesNotThrow(): void {
		$store = new ConversationStore();
		$store->remove('never-added');
		$this->assertFalse($store->has('never-added'));
	}
}
