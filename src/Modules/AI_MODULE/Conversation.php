<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

use Amp\Sync\{LocalMutex, Lock};
use DateTimeInterface;
use Nadybot\Modules\AI_MODULE\Models\Role;
use Safe\DateTimeImmutable;
use stdClass;

/**
 * Manages the conversation history for a single channel/DM key.
 *
 * Internally stores HistoryEntry objects (message + timestamp).
 * All public read methods return pure API messages (stdClass) without timestamps.
 */
class Conversation implements \Countable {
	/** @var list<HistoryEntry> */
	private array $entries = [];

	private LocalMutex $mutex;

	public function __construct() {
		$this->mutex = new LocalMutex();
	}

	/** Returns true if the conversation has no entries. */
	public function isEmpty(): bool {
		return count($this->entries) === 0;
	}

	/**
	 * Acquire an exclusive lock on this conversation.
	 *
	 * Suspends the current fiber until the lock is available.
	 *
	 * @return Lock A lock handle that must be released when done.
	 */
	public function acquire(): Lock {
		return $this->mutex->acquire();
	}

	/** Returns the number of entries in the conversation. */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Append a new API message with an optional timestamp.
	 *
	 * @param stdClass           $message   The API message to store.
	 * @param ?DateTimeInterface $timestamp The creation timestamp (default: now).
	 */
	public function push(stdClass $message, ?DateTimeInterface $timestamp=null): void {
		$this->entries []= new HistoryEntry($message, $timestamp ?? new DateTimeImmutable());
	}

	/**
	 * Create a standard API message object.
	 *
	 * @param Role   $role    The message role.
	 * @param string $content The message content.
	 *
	 * @psalm-suppress MoreSpecificReturnType
	 * @psalm-suppress LessSpecificReturnStatement
	 *
	 * @psalm-pure
	 */
	public static function msg(Role $role, string $content): stdClass {
		return (object)[
			'role' => $role->value,
			'content' => $content,
		];
	}

	/** @return list<stdClass> All API messages (without timestamps). */
	public function getMessages(): array {
		return array_map(
			static fn (HistoryEntry $e): stdClass => $e->message,
			$this->entries,
		);
	}

	/**
	 * @param int  $offset The zero-based offset to start from.
	 * @param ?int $length The number of messages to include (null = remainder).
	 *
	 * @return list<stdClass> API messages for a slice (without timestamps).
	 */
	public function sliceMessages(int $offset, ?int $length=null): array {
		return array_map(
			static fn (HistoryEntry $e): stdClass => $e->message,
			array_slice($this->entries, $offset, $length),
		);
	}

	/**
	 * Replace a range of entries with new API messages.
	 *
	 * Existing entries after the replaced range automatically shift down.
	 * New messages receive the current timestamp.
	 *
	 * @param list<stdClass> $messages
	 */
	public function replaceRange(int $offset, int $length, array $messages): void {
		$entries = array_map(
			static fn (stdClass $m): HistoryEntry => new HistoryEntry($m, new DateTimeImmutable()),
			$messages,
		);
		array_splice($this->entries, $offset, $length, $entries);
	}

	/**
	 * Find the next conversational block starting from $start.
	 *
	 * A block is one user message plus all subsequent messages (assistant,
	 * tool results, …) up to (but not including) the next user message or
	 * the end of the history.
	 */
	public function findNextBlock(int $start): ?Block {
		$user = $this->findNextUserMessage($start);
		if ($user === null) {
			return null;
		}
		$end = ($this->findNextUserMessage($user + 1) ?? $this->count()) - 1;
		return new Block($user, $end);
	}

	/**
	 * Drop the oldest complete turn, keeping the system prompt (index 0).
	 *
	 * @return bool Whether a turn was removed.
	 */
	public function deleteOldestTurn(): bool {
		$block = $this->findNextBlock(1);
		if ($block === null) {
			return false;
		}
		if ($this->findNextUserMessage($block->endIndex + 1) === null) {
			// This is the current (last) turn – never delete it.
			return false;
		}
		array_splice($this->entries, $block->startIndex, $block->length());
		return true;
	}

	/**
	 * Keep removing oldest turns until the count is at most $max.
	 *
	 * @param int $max The maximum number of entries to keep.
	 */
	public function trimToCount(int $max): void {
		while ($this->count() > $max) {
			if (!$this->deleteOldestTurn()) {
				break;
			}
		}
	}

	/**
	 * Replace the oldest block of messages, keeping the system prompt and
	 * the most recent $keepMessages entries untouched.
	 *
	 * The oldest block (everything between index 1 and the last user message
	 * before the final $keepMessages entries) is passed to $replacer.
	 * Its return value replaces that block.
	 *
	 * @param callable(list<stdClass>): list<stdClass> $replacer
	 *
	 * @return bool Whether a block was replaced.
	 */
	public function replaceOldestBlock(int $keepMessages, callable $replacer): bool {
		$count = $this->count();
		$splitIndex = $this->findLastUserMessage($count - $keepMessages);
		if ($splitIndex === null || $splitIndex <= 1) {
			return false;
		}

		$block = $this->sliceMessages(1, $splitIndex - 1);
		assert(count($block) > 0);
		$replacement = $replacer($block);
		$this->replaceRange(1, $splitIndex - 1, $replacement);
		return true;
	}

	/**
	 * Remove all turns whose first message is older than $maxAgeSeconds.
	 *
	 * The system prompt (index 0) is never removed. The current active turn
	 * is also preserved even if it exceeds the age limit.
	 *
	 * @param int $maxAgeSeconds The maximum age in seconds.
	 */
	public function expireOlderThan(int $maxAgeSeconds): void {
		$threshold = (new DateTimeImmutable())->modify("-{$maxAgeSeconds} seconds");
		while (true) {
			$block = $this->findNextBlock(1);
			if ($block === null) {
				return;
			}
			$entry = $this->entries[$block->startIndex];
			if ($entry->timestamp >= $threshold) {
				return;
			}
			array_splice($this->entries, $block->startIndex, $block->length());
		}
	}

	/**
	 * Find the index of the next message with role "user" starting from $start.
	 *
	 * @return ?int The index, or null if none exists.
	 */
	public function findNextUserMessage(int $start): ?int {
		$count = count($this->entries);
		$search = Role::USER->value;
		for ($i = $start; $i < $count; $i++) {
			if (isset($this->entries[$i]->message->role) && $this->entries[$i]->message->role === $search) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Find the index of the last message with role "user" up to $before.
	 *
	 * @return ?int The index, or null if none exists.
	 */
	public function findLastUserMessage(?int $before=null): ?int {
		$end = $before ?? count($this->entries) - 1;
		$search = Role::USER->value;
		for ($i = $end; $i >= 0; $i--) {
			if (isset($this->entries[$i]->message->role) && $this->entries[$i]->message->role === $search) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Get the content of the most recent tool result.
	 *
	 * @return ?string The content, or null if no tool result exists.
	 */
	public function getLastToolResult(): ?string {
		for ($i = count($this->entries) - 1; $i >= 0; $i--) {
			if (isset($this->entries[$i]->message->role) && $this->entries[$i]->message->role === Role::TOOL->value) {
				$content = $this->entries[$i]->message->content ?? null;
				return is_string($content) ? $content : null;
			}
		}
		return null;
	}
}
