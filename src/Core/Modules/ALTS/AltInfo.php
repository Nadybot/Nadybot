<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\DBSchema\Alt;
use Nadybot\Core\Registry;

class AltInfo {
	/** The main char of this character */
	public string $main;

	/**
	 * The list of alts for this character
	 * Format is [name => validated (true) or false]
	 *
	 * @var array<string,bool>
	 */
	public array $alts = [];

	/**
	 * Check of $sender is a validated alt or main
	 */
	public function isValidated(string $sender): bool {
		if ($sender == $this->main) {
			return true;
		}

		foreach ($this->alts as $alt => $validated) {
			if ($sender === $alt) {
				return $validated;
			}
		}

		return false;
	}

	/**
	 * Get a list of all validated alts and the main of $sender
	 * @return string[]
	 */
	public function getAllValidated(string $sender): array {
		if (!$this->isValidated($sender)) {
			return [$sender];
		}
		$arr = [$this->main];
		foreach ($this->alts as $alt => $validated) {
			if ($validated) {
				$arr []= $alt;
			}
		}
		return $arr;
	}

	/**
	 * Get the altlist of this character as a string to display
	 *
	 * @param bool $showValidateLinks Show links to validate the alt if unvalidated
	 * @param bool $firstPageOnly Only show the first page (login alt-list)
	 * @return string
	 */
	public function getAltsBlob(bool $showValidateLinks=false, bool $firstPageOnly=false): string {
		/** @var \Nadybot\Core\DB */
		$db = Registry::getInstance('db');
		/** @var \Nadybot\Core\SettingManager */
		$settingManager = Registry::getInstance('settingManager');
		/** @var \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager */
		$playerManager = Registry::getInstance('playerManager');
		/** @var \Nadybot\Core\BuddylistManager */
		$buddylistManager = Registry::getInstance('buddylistManager');
		/** @var \Nadybot\Core\Text */
		$text = Registry::getInstance('text');
		/** @var \Nadybot\Core\Util */
		$util = Registry::getInstance('util');

		if (count($this->alts) === 0) {
			return "No registered alts.";
		}
		$profDisplay = $settingManager->getInt('alts_profession_display');

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
		if ($settingManager->getBool('alts_show_org') && $character->faction !== null && !$firstPageOnly) {
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
		$data = $db->fetchAll(Alt::class, $sql, $this->main);
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
			if ($settingManager->getBool('alts_show_org') && $row->faction !== null && !$firstPageOnly) {
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

	/**
	 * Get a list of the names of all alts who are online
	 * @return string[]
	 */
	public function getOnlineAlts(): array {
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

	/**
	 * Get a list of the names of all alts
	 * @return string[]
	 */
	public function getAllAlts(): array {
		$online_list = [$this->main, ...array_keys($this->alts)];

		return $online_list;
	}

	public function hasUnvalidatedAlts(): bool {
		foreach ($this->getAllAlts() as $alt) {
			if (!$this->isValidated($alt)) {
				return true;
			}
		}
		return false;
	}

	public function getValidatedMain($sender): string {
		if ($this->isValidated($sender)) {
			return $this->main;
		}
		return $sender;
	}

	public function formatCharName(string $name, ?bool $online): string {
		if ($online) {
			$text = Registry::getInstance('text');
			return $text->makeChatcmd($name, "/tell $name");
		}
		return $name;
	}

	public function formatOnlineStatus(?bool $online): string {
		if ($online) {
			return " - <green>Online<end>";
		}
		return "";
	}
}
