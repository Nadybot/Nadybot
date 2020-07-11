<?php

namespace Budabot\Core\Modules\ALTS;

use Budabot\Core\Registry;

class AltInfo {
	public $main; // The main for this character
	public $alts = array(); // The list of alts for this character

	public function isValidated($sender) {
		if ($sender == $this->main) {
			return true;
		}

		foreach ($this->alts as $alt => $validated) {
			if ($sender == $alt) {
				return ($validated == 1);
			}
		}

		// $sender is not an alt at all, return false
		return false;
	}

	public function getAllValidated($sender) {
		if (!$this->isValidated($sender)) {
			return array($sender);
		} else {
			$arr = array($this->main);
			foreach ($this->alts as $alt => $validated) {
				if ($validated) {
					$arr []= $alt;
				}
			}
			return $arr;
		}
	}

	public function getAltsBlob($showValidateLinks=false, $firstPageOnly=false) {
		/** @var \Budabot\Core\DB */
		$db = Registry::getInstance('db');
		/** @var \Budabot\Core\SettingManager */
		$settingManager = Registry::getInstance('settingManager');
		/** @var \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager */
		$playerManager = Registry::getInstance('playerManager');
		/** @var \Budabot\Core\BuddylistManager */
		$buddylistManager = Registry::getInstance('buddylistManager');
		/** @var \Budabot\Core\Text */
		$text = Registry::getInstance('text');
		/** @var \Budabot\Core\Util */
		$util = Registry::getInstance('util');

		if (count($this->alts) == 0) {
			return "No registered alts.";
		}
		$profDisplay = $settingManager->get('alts_profession_display');

		$online = $buddylistManager->isOnline($this->main);
		$character = $playerManager->getByName($this->main);
		$blob  = $text->alignNumber($character->level, 3, "highlight");
		$blob .= " ";
		$blob .= $text->alignNumber($character->ai_level, 2, "green");
		$blob .= " ";
		if ($profDisplay & 1 && $character->profession !== null) {
			$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".Registry::getInstance('onlineController')->getProfessionId($character->profession)."> ";
		} elseif ($profDisplay & 1) {
			$blob .= "<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ";
		}
		$blob .= $this->formatCharName($this->main, $online);

		$extraInfo = array();
		if ($profDisplay & 2 && $character->profession !== null) {
			$extraInfo []= $util->getProfessionAbbreviation($character->profession);
		}
		if ($profDisplay & 4 && $character->profession !== null) {
			$extraInfo []= $character->profession;
		}
		if ($settingManager->get('alts_show_org') && $character->faction !== null && !$firstPageOnly) {
			$factionColor = strtolower($character->faction);
			$orgName = strlen($character->guild) ? $character->guild : $character->faction;
			$extraInfo []= "<{$factionColor}>{$orgName}<end>";
		}
		if (count($extraInfo)) {
			$blob .= " - " .join(", ", $extraInfo);
		}
		$blob .= $this->formatOnlineStatus($online);
		$blob .= "\n";

		$sql = "SELECT `alt`, `main`, `validated`, p.* ".
			"FROM `alts` a ".
			"LEFT JOIN players p ON (a.alt = p.name AND p.dimension = '<dim>') ".
			"WHERE `main` LIKE ? ";
		if ($settingManager->get('alts_sort') === 'level') {
			$sql .= "ORDER BY level DESC, ai_level DESC, profession ASC, name ASC";
		} elseif ($settingManager->get('alts_sort') === 'name') {
			$sql .= "ORDER BY name ASC";
		}
		$data = $db->query($sql, $this->main);
		$count = count($data) + 1;
		foreach ($data as $row) {
			$online = $buddylistManager->isOnline($row->alt);
			$blob .= $text->alignNumber($row->level, 3, "highlight");
			$blob .= " ";
			$blob .= $text->alignNumber($row->ai_level, 2, "green");
			$blob .= " ";
			if ($profDisplay & 1 && $row->profession !== null) {
				$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".Registry::getInstance('onlineController')->getProfessionId($row->profession)."> ";
			} elseif ($profDisplay & 1) {
				$blob .= "<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ";
			}
			$blob .= $this->formatCharName($row->alt, $online);
			$extraInfo = array();
			if ($profDisplay & 2 && $row->profession !== null) {
				$extraInfo []= $util->getProfessionAbbreviation($row->profession);
			}
			if ($profDisplay & 4 && $row->profession !== null) {
				$extraInfo []= $row->profession;
			}
			if ($settingManager->get('alts_show_org') && $row->faction !== null && !$firstPageOnly) {
				$factionColor = strtolower($row->faction);
				$orgName = strlen($row->guild) ? $row->guild : $row->faction;
				$extraInfo []= "<{$factionColor}>{$orgName}<end>";
			}
			if (count($extraInfo)) {
				$blob .= " - " .join(", ", $extraInfo);
			}
			$blob .= $this->formatOnlineStatus($online);

			if ($showValidateLinks && $row->validated == 0) {
				$blob .= " [Unvalidated] " . $text->makeChatcmd('Validate', "/tell <myname> <symbol>altvalidate {$row->alt}");
			}

			$blob .= "\n";
		}

		$msg = $text->makeBlob("Alts of {$this->main} ($count)", $blob);

		if ($firstPageOnly && is_array($msg)) {
			return $msg[0];
		} else {
			return $msg;
		}
	}

	public function getOnlineAlts() {
		$online_list = array();
		$buddylistManager = Registry::getInstance('buddylistManager');

		if ($buddylistManager->isOnline($this->main)) {
			$online_list []= $this->main;
		}

		foreach ($this->alts as $name => $validated) {
			if ($buddylistManager->isOnline($name)) {
				$online_list []= $name;
			}
		}

		return $online_list;
	}

	public function getAllAlts() {
		$online_list = array();

		$online_list []= $this->main;

		foreach ($this->alts as $name => $validated) {
			$online_list []= $name;
		}

		return $online_list;
	}

	public function hasUnvalidatedAlts() {
		foreach ($this->getAllAlts() as $alt) {
			if (!$this->isValidated($alt)) {
				return true;
			}
		}
		return false;
	}

	public function getValidatedMain($sender) {
		if ($this->isValidated($sender)) {
			return $this->main;
		} else {
			return $sender;
		}
	}

	public function formatCharName($name, $online) {
		if ($online == 1) {
			$text = Registry::getInstance('text');
			return $text->makeChatcmd($name, "/tell $name");
		} else {
			return $name;
		}
	}

	public function formatOnlineStatus($online) {
		if ($online == 1) {
			return " - <green>Online<end>";
		} else {
			return "";
		}
	}
}
