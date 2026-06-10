<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * Holds Conversation instances per channel/DM key.
 */
class ConversationStore {
	/** @var array<string, Conversation> */
	private array $conversations = [];

	/**
	 * Check whether a conversation exists for the given key.
	 *
	 * @param string $key The conversation key to check.
	 */
	public function has(string $key): bool {
		return isset($this->conversations[$key]);
	}

	/**
	 * Get the conversation for a given key.
	 *
	 * @throws \InvalidArgumentException if the key does not exist.
	 */
	public function get(string $key): Conversation {
		if (!isset($this->conversations[$key])) {
			throw new \InvalidArgumentException("No conversation found for key '{$key}'");
		}
		return $this->conversations[$key];
	}

	/**
	 * Get an existing conversation or create a new empty one.
	 *
	 * @param string $key The conversation key to look up or create.
	 */
	public function getOrCreate(string $key): Conversation {
		if (!isset($this->conversations[$key])) {
			$this->conversations[$key] = new Conversation();
		}
		return $this->conversations[$key];
	}

	/**
	 * Remove the conversation for the given key if it exists.
	 *
	 * @param string $key The conversation key to remove.
	 */
	public function remove(string $key): void {
		unset($this->conversations[$key]);
	}

	/**
	 * Return all keys for which a conversation exists.
	 *
	 * @return list<string>
	 */
	public function getKeys(): array {
		return array_keys($this->conversations);
	}
}
