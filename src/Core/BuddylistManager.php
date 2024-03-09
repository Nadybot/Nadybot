<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;

#[NCA\Instance]
class BuddylistManager {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * List of all players on the friendlist, real or just queued up
	 *
	 * @var array<int,BuddylistEntry>
	 */
	public array $buddyList = [];
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	/**
	 * List of all characters currently queued for rebalancing
	 *
	 * @var array<int,bool>
	 */
	private array $inRebalance = [];

	/**
	 * List of all characters currently removed for rebalancing
	 *
	 * @var array<int,array<int,bool>>
	 */
	private array $pendingRebalance = [];

	private ?CommandReply $rebalancingCallback = null;

	/** Get the number of definitively used up buddy slots */
	public function getUsedBuddySlots(): int {
		return count(
			array_filter(
				$this->buddyList,
				function (BuddylistEntry $buddy): bool {
					return $buddy->known;
				}
			)
		);
	}

	/** Check if we are currently rebalancing (the given uid) */
	public function isRebalancing(?int $uid=null): bool {
		if (isset($uid)) {
			return isset($this->pendingRebalance[$uid]);
		}
		return count($this->pendingRebalance) > 0 || count($this->inRebalance) > 0;
	}

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isOnline(string $name): ?bool {
		if (strtolower($this->config->main->character) === strtolower($name)) {
			return true;
		}
		$workerNames = array_column($this->config->worker, "character");
		if (in_array(ucfirst(strtolower($name)), $workerNames, true)) {
			return true;
		}
		$buddy = $this->getBuddy($name);
		return $buddy?->online;
	}

	/** Check if $uid is online (true) or offline/inactive (false) */
	public function checkIsOnline(int $uid): bool {
		$buddy = $this->buddyList[$uid] ?? null;
		$state = [];
		if (isset($buddy)) {
			if ($buddy->known) {
				$state []= 'known';
				if ($buddy->online) {
					$state []= 'online';
				} else {
					$state []= 'offline';
				}
			} else {
				$state []= 'unconfirmed on buddylist';
			}
		} else {
			$state []= 'not on the buddylist';
		}
		$this->logger->debug("Checking if UID {uid} is online. State: {state}", [
			"uid" => $uid,
			"state" => join(", ", $state),
		]);
		$buddyOnline = $this->isUidOnline($uid);
		if (isset($buddyOnline)) {
			return $buddyOnline;
		}

		return $this->chatBot->aoClient->isOnline($uid) ?? false;
	}

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isUidOnline(int $uid): ?bool {
		if ($this->chatBot->aoClient->isOnline(uid: $uid, cacheOnly: true)) {
			return true;
		}
		$buddy = $this->buddyList[$uid] ?? null;
		return ($buddy && $buddy->known) ? $buddy->online : null;
	}

	/**
	 * Get how many friends are really on the buddylist
	 * This ignores the ones that are only queued up for addition
	 */
	public function countConfirmedBuddies(): int {
		return count(
			array_filter(
				$this->buddyList,
				function (BuddylistEntry $entry): bool {
					return $entry->known;
				}
			)
		);
	}

	/** Get information stored about a friend */
	public function getBuddy(string $name): ?BuddylistEntry {
		/** Never trigger an actual ID lookup. If we don't have a buddy's ID, it's inactive */
		$uid = $this->chatBot->getUid(name: $name, cacheOnly: true) ?? false;
		if ($uid === false || !isset($this->buddyList[$uid])) {
			return null;
		}
		return $this->buddyList[$uid];
	}

	/**
	 * Get the names of all people in the friendlist who are online
	 *
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

	public function addName(string $name, string $type): bool {
		if ($type === '') {
			return false;
		}
		$uid = $this->chatBot->getUid($name);
		if ($uid === null) {
			return false;
		}
		return $this->addId($uid, $type);
	}

	/** Add a user id to the bot's friendlist for a given purpose */
	public function addId(int $uid, string $type): bool {
		$name = $this->chatBot->getName(uid: $uid, cacheOnly: true) ?? (string)$uid;
		if (!isset($this->buddyList[$uid])) {
			// Initialize with an unconfirmed entry
			$entry = new BuddylistEntry();
			$entry->uid = $uid;
			$entry->name = $name;
			$entry->known = false;
			$this->logger->info("{buddy} added", ["buddy" => $entry]);
			if (!$this->config->proxy?->enabled && count($this->buddyList) > 999) {
				$this->logger->error("Error adding '{name}' to buddy list: {error}", [
					"name" => $name,
					"error" => "buddy list is full",
				]);
			}
			$this->chatBot->aoClient->buddyAdd($uid);
			// Initialize with an unconfirmed entry
			$this->buddyList[$uid] = $entry;
		} else {
			$oldEntry = $this->buddyList[$uid];
			// If the char is already on our buddylist, but we never received online/offline
			// events, check if the UID was added over 3s ago. If so, send the package (again),
			// because there might have been an error.
			if ($oldEntry->known === false && (time() - $oldEntry->added) >= 3) {
				$this->logger->info("Re-adding {name} to buddylist, because there was no reply yet", [
					"name" => $name,
				]);
				$this->buddyList[$uid]->added = time();
				$this->chatBot->aoClient->buddyAdd($uid);
			}
		}
		if (!$this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->setType($type);
			$this->logger->info("{buddy} added as {type})", [
				"buddy" => $this->buddyList[$uid],
				"type" => $type,
			]);
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
	 *
	 * @return bool true on success, otherwise false
	 */
	public function remove(string $name, string $type=''): bool {
		/** Never trigger an actual ID lookup. If we don't have a buddy's ID, it's inactive */
		$uid = $this->chatBot->getUid(name: $name, cacheOnly: true);
		if ($uid === null) {
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
		if (!isset($this->buddyList[$uid])) {
			return false;
		}
		$name = $this->chatBot->getName(uid: $uid, cacheOnly: true) ?? (string)$uid;
		if ($this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->unsetType($type);
			$this->logger->info("{buddy} removed type '{type}'", [
				"buddy" => $this->buddyList[$uid],
				"type" => $type,
			]);
		}

		if (count($this->buddyList[$uid]->types) === 0) {
			$this->logger->info("{name} buddy removed", ["name" => $name]);
			$this->chatBot->aoClient->buddyRemove($uid);
		}

		return true;
	}

	/** Update the cached information in the friendlist */
	public function update(int $userId, bool $status, int $worker=0): void {
		if ($this->isRebalancing($userId)) {
			unset($this->pendingRebalance[$userId]);
			$this->logger->info("{uid} is now on worker {worker}", [
				"uid" => $userId,
				"worker" => $worker,
			]);
			if (!empty($this->inRebalance)) {
				$uid = array_rand($this->inRebalance);
				$this->pendingRebalance[$uid] = $this->buddyList[$uid]->worker;
				unset($this->inRebalance[$uid]);
				$this->logger->info("Rebalancing {uid}", ["uid" => $uid]);
				$this->chatBot->aoClient->buddyRemove($uid);
			} elseif (empty($this->pendingRebalance)) {
				$this->logger->notice("Rebalancing buddylist done.");
				if (isset($this->rebalancingCallback)) {
					$this->rebalancingCallback->reply("Rebalancing buddylist done.");
					$this->rebalancingCallback = null;
				}
			}
		}
		$sender = $this->chatBot->getName($userId);

		// store buddy info
		$this->buddyList[$userId] ??= new BuddylistEntry();
		$this->buddyList[$userId]->uid = $userId;
		$this->buddyList[$userId]->name = (string)$sender;
		$this->buddyList[$userId]->online = $status;
		$this->buddyList[$userId]->known = true;
		$this->buddyList[$userId]->worker ??= [];
		$this->buddyList[$userId]->worker[$worker] = true;
		$this->logger->info("{buddy} entry added", ["buddy" => $this->buddyList[$userId]]);
	}

	/** Forcefully delete cached information in the friendlist */
	public function updateRemoved(int $uid): void {
		$this->logger->info("UID {uid} removed from buddylist", ["uid" => $uid]);
		if (!$this->isRebalancing($uid)) {
			unset($this->buddyList[$uid]);
			return;
		}
		$worker = array_rand($this->pendingRebalance[$uid]);
		unset($this->pendingRebalance[$uid][$worker]);
		unset($this->buddyList[$uid]->worker[$worker]);
		if (!empty($this->pendingRebalance[$uid])) {
			return;
		}
		$this->logger->info("Re-adding {uid} to buddylist for rebalance", [
			"uid" => $uid,
		]);
		$this->chatBot->aoClient->buddyAdd($uid);
	}

	public function rebalance(CommandReply $callback): void {
		foreach ($this->buddyList as $uid => $buddy) {
			if ($buddy->known) {
				$this->inRebalance[$uid] = true;
			}
		}
		if (empty($this->inRebalance)) {
			return;
		}
		$this->rebalancingCallback = $callback;
		$parallel = (int)floor($this->chatBot->getBuddyListSize() / 100);
		for ($i = 0; $i < $parallel; $i++) {
			if (empty($this->inRebalance)) {
				return;
			}
			$uid = array_rand($this->inRebalance);
			foreach ($this->buddyList[$uid]->worker as $wid => $true) {
				$this->pendingRebalance[$uid] ??= [];
				$this->pendingRebalance[$uid][$wid] = true;
			}
			unset($this->inRebalance[$uid]);
			$this->logger->info("Rebalancing {uid}", ["uid" => $uid]);
			$this->chatBot->aoClient->buddyRemove($uid);
		}
	}

	/** Check if a given UID is on the buddylist for a given type */
	public function buddyHasType(int $uid, string $type): bool {
		$buddy = $this->buddyList[$uid] ?? null;
		return isset($buddy) && $buddy->hasType($type);
	}
}
