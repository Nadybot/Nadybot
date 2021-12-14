<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Database\Query\JoinClause;
use Nadybot\Core\BuddylistManager;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Alt;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

class AltInfo {
	#[NCA\Inject]
	public OnlineController $onlineController;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

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
	 * @psalm-return list<string>
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
		foreach ($this->alts as $alt => $status) {
			if ($onlyMine && $status->added_via !== $this->chatBot->char->name) {
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

		$player = $this->playerManager->getByName($this->main);
		return $this->getAltsBlobForPlayer($player, $firstPageOnly);
	}

	/** @psalm-param callable(string|list<string>) $callback */
	public function getAltsBlobAsync(callable $callback, bool $firstPageOnly=false): void {
		if (count($this->alts) === 0) {
			$callback("No registered alts.");
		}

		$this->playerManager->getByNameAsync(
			function (?Player $player) use ($firstPageOnly, $callback): void {
				$callback($this->getAltsBlobForPlayer($player, $firstPageOnly));
			},
			$this->main
		);
	}

	/** @return string|string[] */
	protected function getAltsBlobForPlayer(?Player $player, bool $firstPageOnly) {
		if (!isset($player)) {
			return "Main character not found.";
		}

		$profDisplay = $this->settingManager->getInt('alts_profession_display')??1;

		$online = $this->buddylistManager->isOnline($this->main);
		$blob  = $this->text->alignNumber($player->level, 3, "highlight");
		$blob .= " ";
		$blob .= $this->text->alignNumber($player->ai_level, 2, "green");
		$blob .= " ";
		if ($profDisplay & 1 && $player->profession !== null) {
			$profId = $this->onlineController->getProfessionId($player->profession);
			if (isset($profId)) {
				$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_{$profId}> ";
			}
		} elseif ($profDisplay & 1) {
			$blob .= "<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ";
		}
		$blob .= $this->formatCharName($this->main, $online);

		$extraInfo = [];
		if ($profDisplay & 2 && $player->profession !== null) {
			$extraInfo []= $this->util->getProfessionAbbreviation($player->profession);
		}
		if ($profDisplay & 4 && $player->profession !== null) {
			$extraInfo []= $player->profession;
		}
		if ($this->settingManager->getBool('alts_show_org') && $player->faction !== null && !$firstPageOnly) {
			$factionColor = strtolower($player->faction);
			$orgName = strlen($player->guild??"") ? $player->guild : $player->faction;
			$extraInfo []= "<{$factionColor}>{$orgName}<end>";
		}
		if (count($extraInfo)) {
			$blob .= " - " .join(", ", $extraInfo);
		}
		$blob .= $this->formatOnlineStatus($online);
		$blob .= "\n";

		$query = $this->db->table("alts AS a")
			->leftJoin("players AS p", function(JoinClause $table) {
				$table->on("a.alt", "p.name");
				$table->where("p.dimension", $this->db->getDim());
			})
			->where("a.main", $this->main)
			->select("a.alt", "a.main", "a.validated_by_main", "a.validated_by_alt", "p.*");
		if ($this->settingManager->get('alts_sort') === 'level') {
			$query->orderByDesc("p.level");
			$query->orderByDesc("p.ai_level");
			$query->orderBy("p.name");
		} elseif ($this->settingManager->get('alts_sort') === 'name') {
			$query->orderBy("p.name");
		}
		$data = $query->asObj(Alt::class)->toArray();
		$count = count($data) + 1;
		foreach ($data as $row) {
			$online = $this->buddylistManager->isOnline($row->alt);
			$blob .= $this->text->alignNumber((int)$row->level, 3, "highlight");
			$blob .= " ";
			$blob .= $this->text->alignNumber((int)$row->ai_level, 2, "green");
			$blob .= " ";
			if ($profDisplay & 1 && $row->profession !== null) {
				$profId = $this->onlineController->getProfessionId($row->profession);
				if (isset($profId)) {
					$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_{$profId}> ";
				}
			} elseif ($profDisplay & 1) {
				$blob .= "<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ";
			}
			$blob .= $this->formatCharName($row->alt, $online);
			$extraInfo = [];
			if ($profDisplay & 2 && $row->profession !== null) {
				$extraInfo []= $this->util->getProfessionAbbreviation($row->profession);
			}
			if ($profDisplay & 4 && $row->profession !== null) {
				$extraInfo []= $row->profession;
			}
			if ($this->settingManager->getBool('alts_show_org') && $row->faction !== null && !$firstPageOnly) {
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

		$msg = $this->text->makeBlob("Alts of {$this->main} ($count)", $blob);

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

		if ($this->buddylistManager->isOnline($this->main)) {
			$online_list []= $this->main;
		}

		foreach ($this->alts as $name => $validated) {
			if ($this->buddylistManager->isOnline($name)) {
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
			return $this->text->makeChatcmd($name, "/tell $name");
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
