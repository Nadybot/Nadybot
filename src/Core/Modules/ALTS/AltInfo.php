<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\DBSchema\Alt;
use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	DB,
	DBSchema\Player,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	SettingManager,
	Text,
};

class AltInfo {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private NickController $nickController;

	#[NCA\Inject]
	private DB $db;

	/**
	 * @param string                            $main The nickname of this character
	 * @param array<string,AltValidationStatus> $alts The list of alts for this character
	 *                                                Format is [
	 *                                                name => validated (true) or false
	 *                                                ]
	 * @param ?string                           $nick The main char of this character
	 */
	public function __construct(
		public string $main,
		public array $alts=[],
		private ?string $nick=null,
		private bool $nickFilled=false,
	) {
	}

	/** Check if $sender is a validated alt or main */
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
	 *
	 * @return list<string>
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
	 *
	 * @return list<string>
	 *
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
	 *
	 * @return list<string>
	 */
	public function getAllMainUnvalidatedAlts(bool $onlyMine=true): array {
		$alts = [];
		foreach ($this->alts as $alt => $status) {
			if ($onlyMine && $status->added_via !== $this->chatBot->char?->name) {
				continue;
			}
			if (!$status->validated_by_main) {
				$alts []= $alt;
			}
		}
		return $alts;
	}

	public function getAltsBlob(bool $firstPageOnly=false): string {
		if (count($this->alts) === 0) {
			return 'No registered alts.';
		}

		$player = $this->playerManager->byName($this->main);
		return $this->getAltsBlobForPlayer($player, $firstPageOnly);
	}

	/**
	 * Get a list of the names of all alts who are online
	 *
	 * @return list<string>
	 */
	public function getOnlineAlts(): array {
		$onlineList = [];

		if ($this->buddylistManager->isOnline($this->main)) {
			$onlineList []= $this->main;
		}

		foreach ($this->alts as $name => $validated) {
			if ($this->buddylistManager->isOnline($name)) {
				$onlineList []= $name;
			}
		}

		return $onlineList;
	}

	/**
	 * Get a list of the names of all alts
	 *
	 * @return list<string>
	 */
	public function getAllAlts(): array {
		$onlineList = [$this->main, ...array_map('strval', array_keys($this->alts))];

		return $onlineList;
	}

	public function hasUnvalidatedAlts(): bool {
		foreach ($this->getAllAlts() as $alt) {
			if (!$this->isValidated($alt)) {
				return true;
			}
		}
		return false;
	}

	public function getValidatedMain(string $sender): string {
		if ($this->isValidated($sender)) {
			return $this->main;
		}
		return $sender;
	}

	public function formatCharName(string $name, ?bool $online): string {
		if ($online) {
			return Text::makeChatcmd($name, "/tell {$name}");
		}
		return $name;
	}

	public function formatOnlineStatus(?bool $online): string {
		if ($online) {
			return ' - <on>Online<end>';
		}
		return '';
	}

	public function getNick(): ?string {
		if ($this->nickFilled === false) {
			$this->nick = $this->nickController->getNickname($this->main);
			$this->nickFilled = true;
		}
		return $this->nick;
	}

	public function getDisplayNick(): ?string {
		$nick = $this->getNick();
		if (!isset($nick)) {
			return null;
		}
		$text = Text::renderPlaceholders(
			$nick,
			['nick' => $nick, 'main' => $this->main]
		);
		return $text;
	}

	protected function getAltsBlobForPlayer(?Player $player, bool $firstPageOnly): string {
		if (!isset($player)) {
			return 'Main character not found.';
		}

		$profDisplay = $this->settingManager->getInt('alts_profession_display')??1;

		$online = $this->buddylistManager->isOnline($this->main);
		$blob  = Text::alignNumber($player->level, 3, 'highlight');
		$blob .= ' ';
		$blob .= Text::alignNumber($player->ai_level, 2, 'green');
		$blob .= ' ';
		if ($profDisplay & 1 && $player->profession !== null) {
			$blob .= $player->profession->toIcon() . ' ';
		} elseif ($profDisplay & 1) {
			$blob .= '<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ';
		}
		$blob .= $this->formatCharName($this->main, $online);

		$extraInfo = [];
		if ($profDisplay & 2 && $player->profession !== null) {
			$extraInfo []= $player->profession->short();
		}
		if ($profDisplay & 4 && $player->profession !== null) {
			$extraInfo []= $player->profession->value;
		}
		if ($this->settingManager->getBool('alts_show_org') === true && !$firstPageOnly) {
			$extraInfo []= $player->faction->inColor($player->guild);
		}
		if (count($extraInfo)) {
			$blob .= ' - ' .implode(', ', $extraInfo);
		}
		$blob .= $this->formatOnlineStatus($online);
		$blob .= "\n";

		$alts = $this->db->table(Alt::getTable(), 'a')
			->where('a.main', $this->main)
			->asObj(AltPlayer::class)
			->filter(static fn (AltPlayer $alt): bool => $alt->alt !== $alt->main);
		$altNames = array_values(array_unique($alts->pluck('alt')->toArray()));
		$playerDataByAlt = $this->playerManager
			->searchByNames($this->db->getDim(), ...$altNames)
			->keyBy('name');
		$alts->each(static function (AltPlayer $alt) use ($playerDataByAlt): void {
			$alt->player = $playerDataByAlt->get($alt->alt);
		});
		if ($this->settingManager->get('alts_sort') === 'level') {
			$alts = $alts->sortBy('alt')
				->sortByDesc('player.ai_level')
				->sortByDesc('player.level');
		} elseif ($this->settingManager->get('alts_sort') === 'name') {
			$alts = $alts->sortBy('alt');
		}
		$count = $alts->count() + 1;
		if ($firstPageOnly) {
			$alts = $alts->slice(0, 15);
		}
		foreach ($alts as $row) {
			/** @var AltPlayer $row */
			$online = $this->buddylistManager->isOnline($row->alt);
			$blob .= Text::alignNumber($row->player?->level??0, 3, 'highlight');
			$blob .= ' ';
			$blob .= Text::alignNumber($row->player?->ai_level??0, 2, 'green');
			$blob .= ' ';
			if ($profDisplay & 1 && isset($row->player) && $row->player->profession !== null) {
				$blob .= $row->player->profession->toIcon() . ' ';
			} elseif ($profDisplay & 1) {
				$blob .= '<img src=tdb://id:GFX_GUI_WINDOW_QUESTIONMARK> ';
			}
			$blob .= $this->formatCharName($row->alt, $online);
			$extraInfo = [];
			if ($profDisplay & 2 && isset($row->player) && $row->player->profession !== null) {
				$extraInfo []= $row->player->profession->short();
			}
			if ($profDisplay & 4 && isset($row->player) && $row->player->profession !== null) {
				$extraInfo []= $row->player->profession->value;
			}
			if (isset($row->player) && $this->settingManager->getBool('alts_show_org') === true && !$firstPageOnly) {
				$extraInfo []= $row->player->faction->inColor($row->player->guild);
			}
			if (count($extraInfo)) {
				$blob .= ' - ' .implode(', ', $extraInfo);
			}
			$blob .= $this->formatOnlineStatus($online);
			if (!$row->validated_by_alt || !$row->validated_by_main) {
				$blob .= ' - <red>not validated<end>';
			}

			$blob .= "\n";
		}
		if ($firstPageOnly && $count > $alts->count()) {
			$blob .= '(+' . ($count - $alts->count()) . ' more)';
		}

		$nick = $this->getDisplayNick();
		$altOwner = $nick ?? $this->main;
		$msg = Text::makeBlob("Alts of {$altOwner} ({$count})", $blob);

		return $msg;
	}
}
