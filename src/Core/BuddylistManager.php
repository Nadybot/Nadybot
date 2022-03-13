<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;

#[NCA\Instance]
class BuddylistManager {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public ConfigFile $config;

	/**
	 * List of all players on the friendlist, real or just queued up
	 * @var array<int,BuddylistEntry>
	 */
	public array $buddyList = [];

	/**
	 * List of all characters currently queued for rebalancing
	 * @var array<int,bool>
	 */
	public array $inRebalance = [];

	/**
	 * Get the number of definitively used up buddy slots
	 */
	public function getUsedBuddySlots(): int {
		return count(
			array_filter(
				$this->buddyList,
				function(BuddylistEntry $buddy): bool {
					return $buddy->known;
				}
			)
		);
	}

	/**
	 * Check if we are currently rebalancing the given uid
	 */
	public function isRebalancing(int $uid): bool {
		return isset($this->inRebalance[$uid]);
	}

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isOnline(string $name): ?bool {
		if (strtolower($this->config->name) === strtolower($name)) {
			return true;
		}
		$buddy = $this->getBuddy($name);
		return $buddy ? $buddy->online : null;
	}

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isUidOnline(int $uid): ?bool {
		if ($this->chatBot->char->id === $uid) {
			return true;
		}
		$buddy = $this->buddyList[$uid] ?? null;
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
		if ($uid === false || $type == '') {
			return false;
		}
		return $this->addId($uid, $type);
	}

	/**
	 * Add a user id to the bot's friendlist for a given purpose
	 */
	public function addId(int $uid, string $type): bool {
		$name = (string)($this->chatBot->id[$uid] ?? $uid);
		if (!isset($this->buddyList[$uid])) {
			$this->logger->info("$name buddy added");
			if (!$this->config->useProxy && count($this->buddyList) > 999) {
				$this->logger->error("Error adding '$name' to buddy list--buddy list is full");
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
			$this->logger->info("$name buddy added (type: $type)");
		}

		return true;
	}

	/**
	 * Remove a user to the bot's friendlist for a given purpose
	 *
	 * This does not necessarily remove the user from the friendlist, because
	 * they might be on it for more than 1 reason. The user is only really removed
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
		return $this->removeId($uid, $type);
	}

	/**
	 * Remove a user from the bot's friendlist for a given purpose
	 *
	 * This does not necessarily remove the user from the friendlist, because
	 * they might be on it for more than 1 reason. The user is noly really removed
	 * when the last reason to be on the list was removed.
	 */
	public function removeId(int $uid, string $type=''): bool {
		$name = $this->chatBot->id[$uid] ?? (string)$uid;
		if (!isset($this->buddyList[$uid])) {
			return false;
		}
		if ($this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->unsetType($type);
			$this->logger->info("$name buddy type removed (type: $type)");
		}

		if (count($this->buddyList[$uid]->types) === 0) {
			$this->logger->info("$name buddy removed");
			$this->chatBot->buddy_remove($uid);
		}

		return true;
	}

	/**
	 * Update the cached information in the friendlist
	 */
	public function update(int $userId, bool $status, int $worker=0): void {
		if ($this->isRebalancing($userId)) {
			unset($this->inRebalance[$userId]);
		}
		$sender = $this->chatBot->lookup_user($userId);

		// store buddy info
		$this->buddyList[$userId] ??= new BuddylistEntry();
		$this->buddyList[$userId]->uid = $userId;
		$this->buddyList[$userId]->name = (string)$sender;
		$this->buddyList[$userId]->online = $status;
		$this->buddyList[$userId]->known = true;
		$this->buddyList[$userId]->worker = $worker;
	}

	/**
	 * Forcefully delete cached information in the friendlist
	 */
	public function updateRemoved(int $uid): void {
		if ($this->isRebalancing($uid)) {
			return;
		}
		unset($this->buddyList[$uid]);
	}

	public function rebalance(): void {
		foreach ($this->buddyList as $uid => $buddy) {
			$this->inRebalance[$uid] = true;
			$this->chatBot->buddy_remove($uid);
			$this->chatBot->buddy_add($uid);
		}
	}

	/**
	 * Check if a given UID is on the buddylist for a given type
	 */
	public function buddyHasType(int $uid, string $type): bool {
		$buddy = $this->buddyList[$uid] ?? null;
		return isset($buddy) && $buddy->hasType($type);
	}
}
