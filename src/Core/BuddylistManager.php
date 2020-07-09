<?php

namespace Budabot\Core;

/**
 * @Instance
 */
class BuddylistManager {

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	public $buddyList = array();

	/**
	 * Check if a friend is online
	 *
	 * @param string $name The name of the friend
	 * @return int|null null when online status is unknown, 1 when buddy is online, 0 when buddy is offline
	 */
	public function isOnline($name) {
		if (strtolower($this->chatBot->vars['name']) == strtolower($name)) {
			return 1;
		} else {
			$buddy = $this->getBuddy($name);
			return ($buddy === null ? null : $buddy['online']);
		}
	}

	/**
	 * Get information stored about a friend
	 *
	 * The information is [
	 *   "uid"    => The UID,
	 *   "name"   => The name,
	 *   "online" => 1 or 0
	 *   "known"  => 1 or 0
	 * ]
	 *
	 * @param string $name
	 * @return mixed[] ["uid" => uid, "name" => name, "online" => 1/0, "known" => 1/0]
	 */
	public function getBuddy($name) {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false || !isset($this->buddyList[$uid])) {
			return null;
		} else {
			return $this->buddyList[$uid];
		}
	}

	/**
	 * Add a user to the bot's friendlist for a given purpose
	 *
	 * @param string $name The name of the player
	 * @param string $type The reason why to add ("member", "admin", "org", "onlineorg", "is_online", "tracking")
	 * @return bool true on success, otherwise false
	 */
	public function add($name, $type) {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false || $type === null || $type == '') {
			return false;
		} else {
			if (!isset($this->buddyList[$uid])) {
				$this->logger->log('debug', "$name buddy added");
				if ($this->chatBot->vars['use_proxy'] != 1 && count($this->buddyList) > 999) {
					$this->logger->log('error', "Error adding '$name' to buddy list--buddy list is full");
				}
				$this->chatBot->buddy_add($uid);
			}

			if (!isset($this->buddyList[$uid]['types'][$type])) {
				$this->buddyList[$uid]['types'][$type] = 1;
				$this->logger->log('debug', "$name buddy added (type: $type)");
			}

			return true;
		}
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
	public function remove($name, $type='') {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false) {
			return false;
		} elseif (isset($this->buddyList[$uid])) {
			if (isset($this->buddyList[$uid]['types'][$type])) {
				unset($this->buddyList[$uid]['types'][$type]);
				$this->logger->log('debug', "$name buddy type removed (type: $type)");
			}

			if (count($this->buddyList[$uid]['types']) == 0) {
				$this->logger->log('debug', "$name buddy removed");
				$this->chatBot->buddy_remove($uid);
			}

			return true;
		} else {
			return false;
		}
	}

	/**
	 * Update the cached information in the friendlist
	 *
	 * @param mixed[] $args [(int)User ID, (int)online, (int)known]
	 * @return void
	 */
	public function update($args) {
		$sender	= $this->chatBot->lookup_user($args[0]);

		// store buddy info
		list($bid, $bonline, $btype) = $args;
		$this->buddyList[$bid]['uid'] = $bid;
		$this->buddyList[$bid]['name'] = $sender;
		$this->buddyList[$bid]['online'] = ($bonline ? 1 : 0);
		$this->buddyList[$bid]['known'] = (ord($btype) ? 1 : 0);
	}

	/**
	 * Forcefully delete cached information in the friendlist
	 *
	 * @param mixed[] $args [(int)User ID]
	 * @return void
	 */
	public function updateRemoved($args) {
		$bid = $args[0];
		unset($this->buddyList[$bid]);
	}
}
