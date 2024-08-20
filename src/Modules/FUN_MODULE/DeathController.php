<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Amp\Promise;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\ParamClass\PRemove;

use function Amp\call;
use function Safe\preg_split;

/**
 * @author Nadyiya (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "death",
		accessLevel: "member",
		description: "Manage your personal death counter",
	),
	NCA\DefineCommand(
		command: "death restart",
		accessLevel: "admin",
		description: "Reset/Wipe death counters",
	),
	NCA\DefineCommand(
		command: "deathmsg",
		accessLevel: "mod",
		description: "Manage custom death messages",
	),
]
class DeathController extends ModuleInstance {
	public const DB_TABLE = 'death_<myname>';
	public const DISPLAY_DONT = 0;
	public const DISPLAY_BEFORE = 1;
	public const DISPLAY_POPUP = 2;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	/** Automatically register someone's char when they use death +1 */
	#[NCA\Setting\Boolean]
	public bool $autoRegisterDeath = false;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/death.csv");
	}

	/** How and whether to display the death counter */
	#[NCA\Setting\Template(
		exampleValues: [
			'character' => 'Nady',
			'counter' => 42,
			'text' => 'R U nubi?',
		],
		options: [
			'never' => "{text}",
			'before' => "You have died <highlight>{counter}<end>x. {text}",
			'short' => "[Counter: {counter}] {text}",
			'short2' => "[Counter: <highlight>{counter}<end>] {text}",
			'supershort' => "[{counter}] {text}",
			'supershort2' => "[<highlight>{counter}<end>] {text}",
		]
	)]
	public string $deathCounterDisplay = "[<highlight>{counter}<end>] {text}";

	public function getDeath(string $character): ?Death {
		return $this->db->table(self::DB_TABLE)
			->where('character', $character)
			->asObj(Death::class)
			->first();
	}

	public function registerDeathCharacter(string $character): Death {
		$death = new Death();
		$death->character = $character;
		$death->counter = 0;
		$this->db->insert(self::DB_TABLE, $death, null);
		return $death;
	}

	/** Show your personal death counter */
	#[NCA\HandlesCommand("death")]
	public function deathCommand(CmdContext $context): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			$context->reply(
				"Your character isn't registered in the current death counter. ".
				"Use <highlight><symbol>death register<end> to take part."
			);
			return;
		}
		$context->reply("You have died <highlight>{$death->counter}<end> times.");
	}

	/** Increase or decrease your personal death counter */
	#[NCA\HandlesCommand("death")]
	public function deathModifyCommand(
		CmdContext $context,
		#[NCA\StrChoice('+', '-')] string $action,
		#[NCA\SpaceOptional] int $delta
	): Generator {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			if ($this->autoRegisterDeath) {
				$death = $this->registerDeathCharacter($context->char->name);
			} else {
				$context->reply(
					"Your character isn't registered in the current death counter. ".
					"Use <highlight><symbol>death register<end> to take part."
				);
				return;
			}
		}
		if ($action === '-') {
			$delta = min($death->counter, $delta);
			if ($death->counter === 0) {
				$context->reply("You cannot die less often than never...");
				return;
			}
			$death->counter -= $delta;
			$this->db->update(self::DB_TABLE, 'character', $death);
			$context->reply("Death counter reduced by <red>{$delta}<end> to <highlight>{$death->counter}<end>.");
			return;
		}
		$death->counter += $delta;
		$this->db->update(self::DB_TABLE, 'character', $death);
		/** @var ?string */
		$deathText = yield $this->getRandomDeathMessage($death);
		if (!isset($deathText)) {
			return;
		}
		$text = $this->text->renderPlaceholders(
			$this->deathCounterDisplay,
			[
				'counter' => $death->counter,
				'name' => $death->character,
				'text' => $deathText,
			]
		);
		$context->reply($text);
	}

	/**
	 * Get a matching death message for a given death
	 *
	 * @param Death $death The death properties
	 *
	 * @return Promise<?string> A matching death message, or null;
	 */
	private function getRandomDeathMessage(Death $death): Promise {
		return call(function () use ($death): Generator {
			/** @var array<Fun> */
			$data = $this->db->table("fun")
				->whereIn("type", ['death', 'death-custom'])
				->whereIlike('content', 'counter=' . $death->counter . ' %')
				->asObj(Fun::class)
				->toArray();
			if (count($data) === 0) {
				/** @var array<Fun> */
				$data = $this->db->table("fun")
					->whereIn("type", ['death', 'death-custom'])
					->asObj(Fun::class)
					->toArray();
			}
			while (count($data) > 0) {
				$key = array_rand($data, 1);

				/** @var ?string */
				$deathMsg = yield $this->deathMsgFits($death, $data[$key]);
				if (isset($deathMsg)) {
					return str_replace("\\n", "\n", $deathMsg);
				}
				unset($data[$key]);
			}
			return null;
		});
	}

	/**
	 * Check if a given death message applies to a given death
	 *
	 * @param Death $death   The death properties
	 * @param Fun    $fun The Fun object with the death message
	 *
	 * @return Promise<?string> Either the death message, or null if it doesn't apply
	 */
	private function deathMsgFits(Death $death, Fun $fun): Promise {
		return call(function () use ($death, $fun): Generator {
			$parts = explode(" ", $fun->content, 2);
			if (count($parts) < 2) {
				return $fun->content;
			}
			$tokens = preg_split('/(!=|[=<>])/', $parts[0], 2, \PREG_SPLIT_DELIM_CAPTURE);
			if (count($tokens) < 3) {
				return $fun->content;
			}

			/** @var bool */
			$matches = yield $this->matchesDeathCheck($tokens[0], $tokens[1], $tokens[2], $death);
			if ($matches) {
				return $parts[1];
			}
			return null;
		});
	}

	/**
	 * Check if the given death-check applies to the player
	 *
	 * @param string $token  The token to check (main, name, prof)
	 * @param string $value  The value to check against
	 * @param Death $death The death properties
	 *
	 * @return Promise<bool> A promise that resolves into true (matches) or false (doesn't match)
	 */
	private function matchesDeathCheck(string $token, string $operator, string $value, Death $death): Promise {
		return call(function () use ($token, $operator, $value, $death): Generator {
			switch ($operator) {
				case '>':
					$comparison = fn (mixed $a, mixed $b): bool => $a > $b;
					break;
				case '<':
					$comparison = fn (mixed $a, mixed $b): bool => $a < $b;
					break;
				case '!=':
					$comparison = fn (mixed $a, mixed $b): bool => $a !== $b;
					break;
				default:
					$comparison = fn (mixed $a, mixed $b): bool => $a === $b;
			}
			switch ($token) {
				case "main":
					return $comparison($this->altsController->getMainOf($death->character), ucfirst(strtolower($value)));
				case "name":
				case "char":
				case "charname":
				case "character":
					return $comparison($death->character, ucfirst(strtolower($value)));
				case "count":
				case "counter":
					return $comparison($death->counter, (int)$value);
			}

			/** @var ?Player */
			$player = yield $this->playerManager->byName($death->character);
			if (!isset($player)) {
				return false;
			}
			switch ($token) {
				case "prof":
				case "profession":
					return $comparison($player->profession, $this->util->getProfessionName($value));
				case "faction":
				case "side":
					return $comparison(strtolower($player->faction), strtolower($value));
				case "gender":
				case "sex":
					return $comparison(strtolower($player->gender), strtolower($value));
				case "race":
				case "breed":
					return $comparison(strtolower($player->breed), strtolower($value));
				case "level":
				case "lvl":
					return $comparison($player->level, (int)$value);
				default:
					return true;
			}
		});
	}

	/** Register your character for taking part in counting deaths */
	#[NCA\HandlesCommand("death")]
	public function deathRegisterCommand(
		CmdContext $context,
		#[NCA\Str('register')] string $action,
	): void {
		$death = $this->getDeath($context->char->name);
		if (isset($death)) {
			$context->reply("Your character is already registered in the current death counter.");
			return;
		}
		$this->registerDeathCharacter($context->char->name);
		$context->reply('Thank you for registering. And now go hunting.');
	}

	/** Show the top number of deaths. The default is top 10 */
	#[NCA\HandlesCommand("death")]
	public function deathTopCommand(
		CmdContext $context,
		#[NCA\Str('top')] string $action,
		#[NCA\SpaceOptional] ?int $num,
	): void {
		$num ??= 10;
		$topDeaths = $this->db->table(self::DB_TABLE)
			->orderByDesc('counter')
			->limit($num)
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/** Show the top number of deaths. The default is top 10 */
	#[NCA\HandlesCommand("death")]
	public function deathTopAllCommand(
		CmdContext $context,
		#[NCA\Str('top')] string $action,
		#[NCA\Str('all')] string $subAction,
	): void {
		$topDeaths = $this->db->table(self::DB_TABLE)
			->orderByDesc('counter')
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/** Reset your death counter to 0 */
	#[NCA\HandlesCommand("death")]
	public function deathResetCommand(
		CmdContext $context,
		#[NCA\Str('reset')] string $action,
	): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			$context->reply("Your character isn't registered in the current death counter.");
			return;
		}
		$death->counter = 0;
		$this->db->update(self::DB_TABLE, 'character', $death);
		$context->reply("Death counter reset to 0.");
	}

	/** Remove yourself from the current death counting */
	#[NCA\HandlesCommand("death")]
	public function deathUnregisterCommand(
		CmdContext $context,
		#[NCA\Str('unregister')] string $action,
	): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			$context->reply("Your character isn't registered in the current death counter.");
			return;
		}
		$this->db->table(self::DB_TABLE)->where('character', $context->char->name)->delete();
		$context->reply("Character removed from counting.");
	}

	/** Restart the death list and unregister everyone */
	#[NCA\HandlesCommand("death restart")]
	public function deathWipeCommand(
		CmdContext $context,
		#[NCA\Str('restart')] string $action,
	): void {
		$this->db->table(self::DB_TABLE)->truncate();
		$context->reply("Death list completely wiped and everyone unregistered.");
	}

	/** Reset everyone on the death list to 0 deaths */
	#[NCA\HandlesCommand("death restart")]
	public function deathRestartCommand(
		CmdContext $context,
		#[NCA\Str('wipe')] string $action,
	): void {
		$this->db->table(self::DB_TABLE)->update(['counter' => 0]);
		$context->reply("Death list wiped and everyone set to 0 deaths.");
	}

	/**
	 * Show the top number of deaths for the sum of all characters of a player.
	 * The default is top 10
	 */
	#[NCA\HandlesCommand("death")]
	public function deathTopPlayersCommand(
		CmdContext $context,
		#[NCA\Str('players')] string $subAction,
		#[NCA\Str('top')] string $action,
		#[NCA\SpaceOptional] ?int $num,
	): void {
		$num ??= 10;
		$query = $this->db->table(self::DB_TABLE, 'd')
			->leftJoin('alts AS a', 'd.character', 'a.alt');
		$topDeaths = $query->groupByRaw($query->colFunc("COALESCE", ["a.main", "d.character"])->getValue())
			->selectRaw($query->colFunc("COALESCE", ["a.main", "d.character"], "character")->getValue())
			->selectRaw($query->colFunc("SUM", "counter", "counter")->getValue())
			->limit($num)
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/**
	 * Show the top number of deaths for the sum of all characters of a player.
	 * The default is top 10
	 */
	#[NCA\HandlesCommand("death")]
	public function deathTopAllPlayersCommand(
		CmdContext $context,
		#[NCA\Str('players')] string $subAction,
		#[NCA\Str('top')] string $action,
		#[NCA\Str('all')] string $all,
	): void {
		$query = $this->db->table(self::DB_TABLE, 'd')
			->leftJoin('alts AS a', 'd.character', 'a.alt');
		$topDeaths = $query->groupByRaw($query->colFunc("COALESCE", ["a.main", "d.character"])->getValue())
			->selectRaw($query->colFunc("COALESCE", ["a.main", "d.character"], "character")->getValue())
			->selectRaw($query->colFunc("SUM", "counter", "counter")->getValue())
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/**
	 * Render the top deaths
	 *
	 * @param Collection<Death> $topDeaths
	 *
	 * @return string[]
	 * @psalm-return list<string>
	 */
	private function renderTopDeaths(Collection $topDeaths): array {
		if ($topDeaths->isEmpty()) {
			return ["No one has registered for dying yet."];
		}
		$maxDeaths = $topDeaths->max('counter');
		$text = "The top " . $topDeaths->count() . " deaths";
		$blob = "<header2>{$text}<end>\n";
		$blob .= $topDeaths->map(function (Death $death) use ($maxDeaths): string {
			return $this->text->alignNumber($death->counter, strlen((string)$maxDeaths)).
				"<tab>{$death->character}";
		})->join("\n");
		return (array)$this->text->makeBlob($text, $blob);
	}

	#[NCA\HandlesCommand("deathmsg")]
	/** List all custom death messages */
	public function listDeathMessages(CmdContext $context): void {
		$lines = $this->db->table("fun")
			->where("type", 'death-custom')
			->asObj(Fun::class)
			->map(function (Fun $entry) use ($context): string {
				$delLink = $this->text->makeChatcmd(
					"remove",
					"/tell <myname> " . $context->getCommand() . " rem " . $entry->id
				);
				return "<tab>- [{$delLink}] {$entry->content}";
			});
		if ($lines->isEmpty()) {
			$context->reply(
				"No custom death messages defined. Use <highlight><symbol>".
				$context->getCommand() . " add &lt;death message&gt;<end> to add one."
			);
			return;
		}
		$msg = $this->text->makeBlob(
			"Defined custom death messages",
			"<header2>Death messages<end>\n" . $lines->join("\n")
		);
		$context->reply($msg);
	}

	/**
	 * Add a new custom death message. Use *counter* as a placeholder for the
	 * number of deaths
	 *
	 * If the first word is a pair in the form key=value, key&gt;value, key&lt;value, or key!=value,
	 * then the death message will only be used if they match.
	 * Possible keys are: name, main, prof, gender, breed, faction, level, counter
	 */
	#[NCA\HandlesCommand("deathmsg")]
	#[NCA\Help\Example(command: "deathmsg add You suck!")]
	#[NCA\Help\Example(command: "deathmsg add counter<10 These are rookie numbers!")]
	#[NCA\Help\Example(command: "deathmsg add prof=enf The doc really sucks")]
	#[NCA\Help\Example(command: "deathmsg add prof!=enf Blame the tank")]
	#[NCA\Help\Example(command: "deathmsg add main=Nady Again, Nady?")]
	public function addDeathMessage(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		string $deathMessage,
	): void {
		$fun = new Fun();
		$fun->type = 'death-custom';
		$fun->content = $deathMessage;
		$id = $this->db->insert("fun", $fun);
		$context->reply("New death message added as <highlight>#{$id}<end>.");
	}

	#[NCA\HandlesCommand("deathmsg")]
	/** Remove a custom death message */
	public function delDeathMessage(
		CmdContext $context,
		PRemove $action,
		int $id,
	): void {
		$deleted = $this->db->table("fun")
			->where("type", 'death-custom')
			->where("id", $id)
			->delete();
		if (!$deleted) {
			$context->reply("The death message <highlight>#{$id}<end> doesn't exist.");
			return;
		}
		$context->reply("Death message <highlight>#{$id}<end> deleted successfully.");
	}
}
