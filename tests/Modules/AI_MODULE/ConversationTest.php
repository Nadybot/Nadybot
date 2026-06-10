<?php declare(strict_types=1);

namespace Nadybot\Tests\Modules\AI_MODULE;

use DateTimeImmutable;
use Nadybot\Modules\AI_MODULE\{Block, Conversation};
use Nadybot\Modules\AI_MODULE\Models\Role;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(Conversation::class)]
class ConversationTest extends TestCase {
	#[Test]
	public function isEmptyReturnsTrueForFreshConversation(): void {
		$conversation = new Conversation();
		$this->assertTrue($conversation->isEmpty());
	}

	#[Test]
	public function countReturnsZeroForFreshConversation(): void {
		$conversation = new Conversation();
		$this->assertSame(0, $conversation->count());
	}

	#[Test]
	public function implementsCountable(): void {
		$conversation = new Conversation();
		$this->assertInstanceOf(\Countable::class, $conversation);
		$this->assertCount(0, $conversation);
	}

	#[Test]
	public function countReturnsActualNumber(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$this->assertCount(2, $conversation);
	}

	#[Test]
	public function acquireReturnsLock(): void {
		$conversation = new Conversation();
		$lock = $conversation->acquire();
		$this->assertInstanceOf(\Amp\Sync\Lock::class, $lock);
		$lock->release();
	}

	#[Test]
	public function pushIncreasesCount(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::USER, 'hello'));
		$this->assertSame(1, $conversation->count());
		$this->assertFalse($conversation->isEmpty());
	}

	#[Test]
	public function getMessagesReturnsPureStdClassWithoutTimestamp(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$messages = $conversation->getMessages();
		$this->assertCount(1, $messages);
		$this->assertInstanceOf(\stdClass::class, $messages[0]);
		$this->assertObjectNotHasProperty('_ts', $messages[0]);
		$this->assertSame(Role::SYSTEM->value, $messages[0]->role);
	}

	#[Test]
	public function sliceMessagesReturnsPureMessages(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$messages = $conversation->sliceMessages(1);
		$this->assertCount(1, $messages);
		$this->assertObjectNotHasProperty('_ts', $messages[0]);
	}

	#[Test]
	public function replaceRangeReplacesEntries(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->replaceRange(1, 1, [
			Conversation::msg(Role::ASSISTANT, 'a1'),
		]);
		$this->assertSame(2, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame(Role::ASSISTANT->value, $messages[1]->role);
	}

	#[Test]
	public function deleteOldestTurnRemovesCompleteTurn(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a2'));

		$this->assertTrue($conversation->deleteOldestTurn());
		$this->assertSame(3, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame(Role::SYSTEM->value, $messages[0]->role);
		$this->assertSame(Role::USER->value, $messages[1]->role);
		$this->assertSame('u2', $messages[1]->content);
	}

	#[Test]
	public function deleteOldestTurnKeepsSystemPrompt(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));

		$conversation->deleteOldestTurn();
		$messages = $conversation->getMessages();
		$this->assertSame(Role::SYSTEM->value, $messages[0]->role);
	}

	#[Test]
	public function deleteOldestTurnReturnsFalseWhenOnlyOneTurn(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));

		$this->assertFalse($conversation->deleteOldestTurn());
	}

	#[Test]
	public function trimToCountRemovesOldestTurns(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a2'));

		$conversation->trimToCount(3);
		$this->assertSame(3, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame('sys', $messages[0]->content);
		$this->assertSame('u2', $messages[1]->content);
	}

	#[Test]
	public function expireOlderThanRemovesExpiredTurns(): void {
		$conversation = new Conversation();
		$conversation->push(
			Conversation::msg(Role::SYSTEM, 'sys'),
			new DateTimeImmutable('-3 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::USER, 'u1'),
			new DateTimeImmutable('-2 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a1'),
			new DateTimeImmutable('-2 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::USER, 'u2'),
			new DateTimeImmutable('-5 minutes'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a2'),
			new DateTimeImmutable('-5 minutes'),
		);

		$conversation->expireOlderThan(3_600); // 1 hour
		$messages = $conversation->getMessages();
		$this->assertSame(3, $conversation->count());
		$this->assertSame('sys', $messages[0]->content);
		$this->assertSame('u2', $messages[1]->content);
		$this->assertSame('a2', $messages[2]->content);
	}

	// ─── replaceOldestBlock ───────────────────────────────────────────

	#[Test]
	public function replaceOldestBlockReturnsFalseWhenNotEnoughMessages(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));

		$result = $conversation->replaceOldestBlock(2, static fn (): array => []);
		$this->assertFalse($result);
	}

	#[Test]
	public function replaceOldestBlockReturnsFalseWhenSplitIndexIsOne(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));

		$result = $conversation->replaceOldestBlock(0, static fn (): array => []);
		$this->assertFalse($result);
	}

	#[Test]
	public function replaceOldestBlockReplacesOldestBlock(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a2'));
		$conversation->push(Conversation::msg(Role::USER, 'u3'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a3'));

		$received = [];
		$result = $conversation->replaceOldestBlock(2, static function (array $messages) use (&$received): array {
			$received = $messages;
			return [
				Conversation::msg(Role::USER, 'summary-prompt'),
				Conversation::msg(Role::ASSISTANT, 'summary-text'),
			];
		});
		$this->assertTrue($result);
		$this->assertSame(5, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame('sys', $messages[0]->content);
		$this->assertSame('summary-prompt', $messages[1]->content);
		$this->assertSame('summary-text', $messages[2]->content);
		$this->assertSame('u3', $messages[3]->content);
		$this->assertSame('a3', $messages[4]->content);
		// Übergebener Slice: u1, a1, u2, a2 (Index 1 bis splitIndex-1)
		$this->assertSame(4, count($received));
		$this->assertSame('u1', $received[0]->content);
		$this->assertSame('a2', $received[3]->content);
	}

	#[Test]
	public function replaceOldestBlockKeepsSystemPrompt(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a2'));

		$conversation->replaceOldestBlock(2, static fn (): array => [
			Conversation::msg(Role::USER, 'summary-prompt'),
			Conversation::msg(Role::ASSISTANT, 'summary-text'),
		]);
		$messages = $conversation->getMessages();
		$this->assertSame('sys', $messages[0]->content);
	}

	#[Test]
	public function replaceOldestBlockMultipleRuns(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a2'));
		$conversation->push(Conversation::msg(Role::USER, 'u3'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a3'));
		$conversation->push(Conversation::msg(Role::USER, 'u4'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a4'));

		// Erster Lauf: ersetzt u1..a3 (splitIndex bei u3, keep=2)
		$first = $conversation->replaceOldestBlock(2, static fn (): array => [
			Conversation::msg(Role::USER, 'sum1'),
			Conversation::msg(Role::ASSISTANT, 'sum1-text'),
		]);
		$this->assertTrue($first);
		$this->assertSame(5, $conversation->count());

		// Zweiter Lauf: ersetzt sum1..a2 (splitIndex bei u4, keep=2)
		$second = $conversation->replaceOldestBlock(2, static fn (): array => [
			Conversation::msg(Role::USER, 'sum2'),
			Conversation::msg(Role::ASSISTANT, 'sum2-text'),
		]);
		$this->assertTrue($second);
		$this->assertSame(5, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame('sys', $messages[0]->content);
		$this->assertSame('sum2', $messages[1]->content);
		$this->assertSame('sum2-text', $messages[2]->content);
		$this->assertSame('u4', $messages[3]->content);
		$this->assertSame('a4', $messages[4]->content);
	}

	#[Test]
	public function expireOlderThanKeepsSystemPrompt(): void {
		$conversation = new Conversation();
		$conversation->push(
			Conversation::msg(Role::SYSTEM, 'sys'),
			new DateTimeImmutable('-3 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::USER, 'u1'),
			new DateTimeImmutable('-2 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a1'),
			new DateTimeImmutable('-2 hours'),
		);

		$conversation->expireOlderThan(3_600);
		// Auch der aktuelle (aber abgelaufene) Turn wird gelöscht.
		$this->assertSame(1, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame(Role::SYSTEM->value, $messages[0]->role);
	}

	#[Test]
	public function expireOlderThanRemovesCurrentTurnIfExpired(): void {
		$conversation = new Conversation();
		$conversation->push(
			Conversation::msg(Role::SYSTEM, 'sys'),
			new DateTimeImmutable('-3 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::USER, 'u1'),
			new DateTimeImmutable('-2 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a1'),
			new DateTimeImmutable('-2 hours'),
		);

		$conversation->expireOlderThan(3_600);
		// Auch der aktuelle (aber abgelaufene) Turn wird gelöscht.
		$this->assertSame(1, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame('sys', $messages[0]->content);
	}

	#[Test]
	public function findNextUserMessageFindsCorrectIndex(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a2'));

		$this->assertSame(2, $conversation->findNextUserMessage(1));
		$this->assertNull($conversation->findNextUserMessage(3));
	}

	#[Test]
	public function findLastUserMessageFindsCorrectIndex(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));

		$this->assertSame(3, $conversation->findLastUserMessage());
		$this->assertSame(1, $conversation->findLastUserMessage(2));
	}

	#[Test]
	public function getLastToolResultReturnsContent(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::TOOL, 'tool-result'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));

		$this->assertSame('tool-result', $conversation->getLastToolResult());
	}

	#[Test]
	public function getLastToolResultReturnsNullWhenMissing(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));

		$this->assertNull($conversation->getLastToolResult());
	}

	/** @psalm-suppress ArgumentTypeCoercion */
	#[Test]
	public function getLastToolResultIgnoresNonStringContent(): void {
		$conversation = new Conversation();
		$conversation->push((object)['role' => Role::TOOL->value, 'content' => null]);

		$this->assertNull($conversation->getLastToolResult());
	}

	// ─── Edge Cases ───────────────────────────────────────────────────

	#[Test]
	public function sliceMessagesReturnsEmptyArrayForEmptyConversation(): void {
		$conversation = new Conversation();
		$this->assertSame([], $conversation->sliceMessages(0));
	}

	#[Test]
	public function deleteOldestTurnOnEmptyConversationReturnsFalse(): void {
		$conversation = new Conversation();
		$this->assertFalse($conversation->deleteOldestTurn());
	}

	#[Test]
	public function deleteOldestTurnWithOnlySystemPromptReturnsFalse(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$this->assertFalse($conversation->deleteOldestTurn());
	}

	#[Test]
	public function trimToCountOnEmptyConversationDoesNothing(): void {
		$conversation = new Conversation();
		$conversation->trimToCount(0);
		$this->assertSame(0, $conversation->count());
	}

	#[Test]
	public function trimToCountWithZeroKeepsOnlySystemPrompt(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->trimToCount(0);
		// System-Prompt bleibt erhalten, da deleteOldestTurn bei nur einem Turn false liefert
		$this->assertSame(3, $conversation->count());
	}

	#[Test]
	public function expireOlderThanOnEmptyConversationDoesNothing(): void {
		$conversation = new Conversation();
		$conversation->expireOlderThan(3_600);
		$this->assertSame(0, $conversation->count());
	}

	#[Test]
	public function expireOlderThanWithZeroSecondsRemovesNothing(): void {
		$conversation = new Conversation();
		$conversation->push(
			Conversation::msg(Role::SYSTEM, 'sys'),
			new DateTimeImmutable('-1 second'),
		);
		$conversation->push(
			Conversation::msg(Role::USER, 'u1'),
			new DateTimeImmutable('-1 second'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a1'),
			new DateTimeImmutable('-1 second'),
		);
		$conversation->expireOlderThan(0);
		// Bei 0 Sekunden ist alles älter, auch der aktuelle Turn.
		$this->assertSame(1, $conversation->count());
	}

	#[Test]
	public function expireOlderThanWithVeryLargeValueRemovesNothing(): void {
		$conversation = new Conversation();
		$conversation->push(
			Conversation::msg(Role::SYSTEM, 'sys'),
			new DateTimeImmutable('-1 hour'),
		);
		$conversation->push(
			Conversation::msg(Role::USER, 'u1'),
			new DateTimeImmutable('-30 minutes'),
		);
		$conversation->expireOlderThan(86_400); // 24h
		$this->assertSame(2, $conversation->count());
	}

	#[Test]
	public function findNextUserMessageOnEmptyConversationReturnsNull(): void {
		$conversation = new Conversation();
		$this->assertNull($conversation->findNextUserMessage(0));
	}

	// ─── findNextBlock ──────────────────────────────────────────────

	#[Test]
	public function findNextBlockReturnsNullForEmptyConversation(): void {
		$conversation = new Conversation();
		$this->assertNull($conversation->findNextBlock(0));
	}

	#[Test]
	public function findNextBlockReturnsNullWhenNoUserMessage(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$this->assertNull($conversation->findNextBlock(1));
	}

	#[Test]
	public function findNextBlockFindsCompleteBlock(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));
		$conversation->push(Conversation::msg(Role::TOOL, 'tool'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));

		$block = $conversation->findNextBlock(1);
		$this->assertNotNull($block);
		$this->assertSame(1, $block->startIndex);
		$this->assertSame(3, $block->endIndex);
	}

	#[Test]
	public function findNextBlockFindsLastBlock(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::ASSISTANT, 'a1'));

		$block = $conversation->findNextBlock(0);
		$this->assertNotNull($block);
		$this->assertSame(0, $block->startIndex);
		$this->assertSame(1, $block->endIndex);
	}

	#[Test]
	public function findNextBlockIgnoresEarlierMessages(): void {
		$conversation = new Conversation();
		$conversation->push(Conversation::msg(Role::SYSTEM, 'sys'));
		$conversation->push(Conversation::msg(Role::USER, 'u1'));
		$conversation->push(Conversation::msg(Role::USER, 'u2'));

		$block = $conversation->findNextBlock(2);
		$this->assertNotNull($block);
		$this->assertSame(2, $block->startIndex);
		$this->assertSame(2, $block->endIndex);
	}

	// ─── Edge Cases ───────────────────────────────────────────────────
	#[Test]
	public function findLastUserMessageOnEmptyConversationReturnsNull(): void {
		$conversation = new Conversation();
		$this->assertNull($conversation->findLastUserMessage());
	}

	#[Test]
	public function getLastToolResultOnEmptyConversationReturnsNull(): void {
		$conversation = new Conversation();
		$this->assertNull($conversation->getLastToolResult());
	}

	#[Test]
	public function replaceRangeOnEmptyConversationInsertsEntries(): void {
		$conversation = new Conversation();
		$conversation->replaceRange(0, 0, [
			Conversation::msg(Role::SYSTEM, 'sys'),
		]);
		$this->assertSame(1, $conversation->count());
		$messages = $conversation->getMessages();
		$this->assertSame('sys', $messages[0]->content);
	}

	#[Test]
	public function multipleTurnsCanBeExpired(): void {
		$conversation = new Conversation();
		$conversation->push(
			Conversation::msg(Role::SYSTEM, 'sys'),
			new DateTimeImmutable('-3 hours'),
		);
		// Turn 1: alt
		$conversation->push(
			Conversation::msg(Role::USER, 'u1'),
			new DateTimeImmutable('-2 hours'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a1'),
			new DateTimeImmutable('-2 hours'),
		);
		// Turn 2: alt
		$conversation->push(
			Conversation::msg(Role::USER, 'u2'),
			new DateTimeImmutable('-90 minutes'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a2'),
			new DateTimeImmutable('-90 minutes'),
		);
		// Turn 3: neu
		$conversation->push(
			Conversation::msg(Role::USER, 'u3'),
			new DateTimeImmutable('-5 minutes'),
		);
		$conversation->push(
			Conversation::msg(Role::ASSISTANT, 'a3'),
			new DateTimeImmutable('-5 minutes'),
		);

		$conversation->expireOlderThan(3_600); // 1 hour
		$messages = $conversation->getMessages();
		$this->assertSame(3, $conversation->count());
		$this->assertSame('sys', $messages[0]->content);
		$this->assertSame('u3', $messages[1]->content);
	}
}
