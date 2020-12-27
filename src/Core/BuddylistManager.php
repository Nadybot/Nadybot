<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 */
class BuddylistManager {
	/**
	 * @Inject
	 */
	public Nadybot $chatBot;

	/**
	 * @Logger
	 */
	public LoggerWrapper $logger;

	/**
	 * List of all player son the friendlist, real or just queued up
	 * @var array<int,BuddylistEntry>
	 */
	public array $buddyList = [];

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isOnline(string $name): ?bool {
		if (strtolower($this->chatBot->vars['name']) === strtolower($name)) {
			return true;
		}
		$buddy = $this->getBuddy($name);
		return $buddy ? $buddy->online : null;
	}

	/**
	 * Get how many friends are really on the buddylist
	 * This ignores the ones that are only queued up for addition
	 */
	public function countConfirmedBuddies(): int {
		return count(
			array_filter(
				$this->buddyList,
				function(BuddylistEntry $entry): bool {
					return $entry->known;
				}
			)
		);
	}

	/**
	 * Get information stored about a friend
	 *
	 * @param int|string $name
	 */
	public function getBuddy($name): ?BuddylistEntry {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false || !isset($this->buddyList[$uid])) {
			return null;
		}
		return $this->buddyList[$uid];
	}

	/**
	 * Get the names of all people in the friendlist who are online
	 * @return string[]
	 */
	public function getOnline(): array {
		$result = [];
		foreach ($this->buddyList as $uid => $data) {
			if ($data->online) {
				$result []= $data->name;
			}
		}
		return $result;
	}

	/**
	 * Add a user to the bot's friendlist for a given purpose
	 *
	 * @param string $name The name of the player
	 * @param string $type The reason why to add ("member", "admin", "org", "onlineorg", "is_online", "tracking")
	 * @return bool true on success, otherwise false
	 */
	public function add(string $name, string $type): bool {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false || $type === null || $type == '') {
			return false;
		}
		if (!isset($this->buddyList[$uid])) {
			$this->logger->log('debug', "$name buddy added");
			if ($this->chatBot->vars['use_proxy'] != 1 && count($this->buddyList) > 999) {
				$this->logger->log('error', "Error adding '$name' to buddy list--buddy list is full");
			}
			$this->chatBot->buddy_add($uid);
			// Initialize with an unconfirmed entry
			$this->buddyList[$uid] = new BuddylistEntry();
			$this->buddyList[$uid]->uid = $uid;
			$this->buddyList[$uid]->name = $name;
			$this->buddyList[$uid]->known = false;
		}
		if (!$this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->setType($type);
			$this->logger->log('debug', "$name buddy added (type: $type)");
		}

		return true;
	}

	/**
	 * Remove a user to the bot's friendlist for a given purpose
	 *
	 * This does not necessarily remove the user from the friendlist, because
	 * they might be on it for more than 1 reason. The user is oly really removed
	 * when the last reason to be on the list was removed.
	 *
	 * @param string $name The name of the player
	 * @param string $type The reason for which to remove ("member", "admin", "org", "onlineorg", "is_online", "tracking")
	 * @return bool true on success, otherwise false
	 */
	public function remove(string $name, string $type=''): bool {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false) {
			return false;
		}
		if (!isset($this->buddyList[$uid])) {
			return false;
		}
		if ($this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->unsetType($type);
			$this->logger->log('debug', "$name buddy type removed (type: $type)");
		}

		if (count($this->buddyList[$uid]->types) === 0) {
			$this->logger->log('debug', "$name buddy removed");
			$this->chatBot->buddy_remove($uid);
		}

		return true;
	}

	/**
	 * Update the cached information in the friendlist
	 */
	public function update(int $userId, bool $status): void {
		$sender = $this->chatBot->lookup_user($userId);

		// store buddy info
		$this->buddyList[$userId] ??= new BuddylistEntry();
		$this->buddyList[$userId]->uid = $userId;
		$this->buddyList[$userId]->name = (string)$sender;
		$this->buddyList[$userId]->online = $status;
		$this->buddyList[$userId]->known = true;
	}

	/**
	 * Forcefully delete cached information in the friendlist
	 */
	public function updateRemoved(int $uid): void {
		unset($this->buddyList[$uid]);
	}
}
