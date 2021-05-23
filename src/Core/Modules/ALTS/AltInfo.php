<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Illuminate\Database\Query\JoinClause;
use Nadybot\Core\DBSchema\Alt;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Registry;

class AltInfo {
	/** The main char of this character */
	public string $main;

	/**
	 * The list of alts for this character
	 * Format is [name => validated (true) or false]
	 *
	 * @var array<string,AltValidationStatus>
	 */
	public array $alts = [];

	/**
	 * Check if $sender is a validated alt or main
	 */
	public function isValidated(string $sender): bool {
		$sender = ucfirst(strtolower($sender));
		if ($sender === $this->main) {
			return true;
		}

		if (!isset($this->alts[$sender])) {
			return false;
		}
		return $this->alts[$sender]->validated_by_alt && $this->alts[$sender]->validated_by_main;
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
			if ($validated->validated_by_alt && $validated->validated_by_main) {
				$arr []= $alt;
			}
		}
		return $arr;
	}

	/**
	 * Get a list of all validated alts
	 * @return string[]
	 */
	public function getAllValidatedAlts(): array {
		$alts = [];
		foreach ($this->alts as $alt => $status) {
			if ($this->isValidated($alt)) {
				$alts []= $alt;
			}
		}
		return $alts;
	}

	/**
	 * Get a list of all alts requiring validation from main
	 * @return string[]
	 */
	public function getAllMainUnvalidatedAlts(bool $onlyMine=true): array {
		$alts = [];
		/** @var Nadybot */
		$chatBot = Registry::getInstance('chatBot');
		foreach ($this->alts as $alt => $status) {
			if ($onlyMine && $status->added_via !== $chatBot->vars["name"]) {
				continue;
			}
			if (!$status->validated_by_main) {
				$alts []= $alt;
			}
		}
		return $alts;
	}

	/**
	 * Get the altlist of this character as a string to display
	 *
	 * @param bool $firstPageOnly Only show the first page (login alt-list)
	 * @return string|string[]
	 */
	public function getAltsBlob(bool $firstPageOnly=false) {
		if (count($this->alts) === 0) {
			return "No registered alts.";
		}

		/** @var \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager */
		$playerManager = Registry::getInstance('playerManager');

		$player = $playerManager->getByName($this->main);
		return $this->getAltsBlobForPlayer($player, $firstPageOnly);
	}

	public function getAltsBlobAsync(callable $callback, bool $firstPageOnly=false): void {
		if (count($this->alts) === 0) {
			$callback("No registered alts.");
		}

		/** @var \Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager */
		$playerManager = Registry::getInstance('playerManager');

		$playerManager->getByNameAsync(
			function (?Player $player) use ($firstPageOnly, $callback): void {
				$callback($this->getAltsBlobForPlayer($player, $firstPageOnly));
			},
			$this->main
		);
	}

	protected function getAltsBlobForPlayer(?Player $player, bool $firstPageOnly) {
		if (!isset($player)) {
			return "Main character not found.";
		}
		/** @var \Nadybot\Core\DB */
		$db = Registry::getInstance('db');
		/** @var \Nadybot\Core\SettingManager */
		$settingManager = Registry::getInstance('settingManager');
		/** @var \Nadybot\Core\BuddylistManager */
		$buddylistManager = Registry::getInstance('buddylistManager');
		/** @var \Nadybot\Core\Text */
		$text = Registry::getInstance('text');
		/** @var \Nadybot\Core\Util */
		$util = Registry::getInstance('util');

		$profDisplay = $settingManager->getInt('alts_profession_display');

		$online = $buddylistManager->isOnline($this->main);
		$blob  = $text->alignNumber($player->level, 3, "highlight");
		$blob .= " ";
		$blob .= $text->alignNumber($player->ai_level, 2, "green");
		$blob .= " ";
		if ($profDisplay & 1 && $player->profession !== null) {
			$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".Registry::getInstance('onlineController')->getProfessionId($player->profession)."> ";
		} elseif ($profDisplay & 1) {
			$blob .= "<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ";
		}
		$blob .= $this->formatCharName($this->main, $online);

		$extraInfo = [];
		if ($profDisplay & 2 && $player->profession !== null) {
			$extraInfo []= $util->getProfessionAbbreviation($player->profession);
		}
		if ($profDisplay & 4 && $player->profession !== null) {
			$extraInfo []= $player->profession;
		}
		if ($settingManager->getBool('alts_show_org') && $player->faction !== null && !$firstPageOnly) {
			$factionColor = strtolower($player->faction);
			$orgName = strlen($player->guild) ? $player->guild : $player->faction;
			$extraInfo []= "<{$factionColor}>{$orgName}<end>";
		}
		if (count($extraInfo)) {
			$blob .= " - " .join(", ", $extraInfo);
		}
		$blob .= $this->formatOnlineStatus($online);
		$blob .= "\n";

		$query = $db->table("alts AS a")
			->leftJoin("players AS p", function(JoinClause $table) use ($db) {
				$table->on("a.alt", "p.name");
				$table->where("p.dimension", $db->getDim());
			})
			->where("a.main", $this->main)
			->select("a.alt", "a.main", "a.validated_by_main", "a.validated_by_alt", "p.*");
		if ($settingManager->get('alts_sort') === 'level') {
			$query->orderByDesc("p.level");
			$query->orderByDesc("p.ai_level");
			$query->orderBy("p.name");
		} elseif ($settingManager->get('alts_sort') === 'name') {
			$query->orderBy("p.name");
		}
		$data = $query->asObj(Alt::class)->toArray();
		$count = count($data) + 1;
		foreach ($data as $row) {
			$online = $buddylistManager->isOnline($row->alt);
			$blob .= $text->alignNumber((int)$row->level, 3, "highlight");
			$blob .= " ";
			$blob .= $text->alignNumber((int)$row->ai_level, 2, "green");
			$blob .= " ";
			if ($profDisplay & 1 && $row->profession !== null) {
				$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".Registry::getInstance('onlineController')->getProfessionId($row->profession)."> ";
			} elseif ($profDisplay & 1) {
				$blob .= "<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ";
			}
			$blob .= $this->formatCharName($row->alt, $online);
			$extraInfo = [];
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
			if (!$row->validated_by_alt || !$row->validated_by_main) {
				$blob .= " - <red>not validated<end>";
			}

			$blob .= "\n";
		}

		$msg = $text->makeBlob("Alts of {$this->main} ($count)", $blob);

		if ($firstPageOnly && is_array($msg)) {
			return $msg[0];
		}
		return $msg;
	}

	/**
	 * Get a list of the names of all alts who are online
	 * @return string[]
	 */
	public function getOnlineAlts(): array {
		$online_list = [];
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
