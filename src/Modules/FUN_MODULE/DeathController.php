<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use function Safe\preg_split;
use Illuminate\Support\Collection;
use Nadybot\Core\Modules\{
	ALTS\AltsController,
	PLAYER_LOOKUP\PlayerManager,
};
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\Remove,
	Attributes\Parameter\SpaceOptional,
	Attributes\Parameter\Str,
	Attributes\Parameter\StrChoice,
	CmdContext,
	DB,
	DBSchema\Alt,
	ModuleInstance,
	ParamClass\PUuid,
	Text,
};

/**
 * @author Nadyiya (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'death',
		accessLevel: 'member',
		description: 'Manage your personal death counter',
	),
	NCA\DefineCommand(
		command: 'death restart',
		accessLevel: 'admin',
		description: 'Reset/Wipe death counters',
	),
	NCA\DefineCommand(
		command: 'deathmsg',
		accessLevel: 'mod',
		description: 'Manage custom death messages',
	),
]
class DeathController extends ModuleInstance {
	public const DISPLAY_DONT = 0;
	public const DISPLAY_BEFORE = 1;
	public const DISPLAY_POPUP = 2;

	/** Automatically register someone's char when they use death +1 */
	#[NCA\Setting\Boolean]
	public bool $autoRegisterDeath = false;

	/** How and whether to display the death counter */
	#[NCA\Setting\Template(
		exampleValues: [
			'character' => 'Nady',
			'counter' => 42,
			'text' => 'R U nubi?',
		],
		options: [
			'never' => '{text}',
			'before' => 'You have died <highlight>{counter}<end>x. {text}',
			'short' => '[Counter: {counter}] {text}',
			'short2' => '[Counter: <highlight>{counter}<end>] {text}',
			'supershort' => '[{counter}] {text}',
			'supershort2' => '[<highlight>{counter}<end>] {text}',
		]
	)]
	public string $deathCounterDisplay = '[<highlight>{counter}<end>] {text}';

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/death.csv');
	}

	public function getDeath(string $character): ?Death {
		return $this->db->table(Death::getTable())
			->where('character', $character)
			->firstObj(Death::class);
	}

	public function registerDeathCharacter(string $character): Death {
		$death = new Death(
			character: $character,
			counter: 0,
		);
		$this->db->insert($death);
		return $death;
	}

	/** Show your personal death counter */
	#[NCA\HandlesCommand('death')]
	public function deathCommand(CmdContext $context): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			$context->reply(
				"Your character isn't registered in the current death counter. ".
				'Use <highlight><symbol>death register<end> to take part.'
			);
			return;
		}
		$context->reply("You have died <highlight>{$death->counter}<end> times.");
	}

	/** Increase or decrease your personal death counter */
	#[NCA\HandlesCommand('death')]
	public function deathModifyCommand(
		CmdContext $context,
		#[StrChoice('+', '-')] string $action,
		#[SpaceOptional] int $delta
	): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			if ($this->autoRegisterDeath) {
				$death = $this->registerDeathCharacter($context->char->name);
			} else {
				$context->reply(
					"Your character isn't registered in the current death counter. ".
					'Use <highlight><symbol>death register<end> to take part.'
				);
				return;
			}
		}
		if ($action === '-') {
			$delta = min($death->counter, $delta);
			if ($death->counter === 0) {
				$context->reply('You cannot die less often than never...');
				return;
			}
			$death->counter -= $delta;
			$this->db->update($death);
			$context->reply("Death counter reduced by <red>{$delta}<end> to <highlight>{$death->counter}<end>.");
			return;
		}
		$death->counter += $delta;
		$this->db->update($death);

		$deathText = $this->getRandomDeathMessage($death);
		if (!isset($deathText)) {
			return;
		}
		$text = Text::renderPlaceholders(
			$this->deathCounterDisplay,
			[
				'counter' => $death->counter,
				'name' => $death->character,
				'text' => $deathText,
			]
		);
		$context->reply($text);
	}

	/** Register your character for taking part in counting deaths */
	#[NCA\HandlesCommand('death')]
	public function deathRegisterCommand(
		CmdContext $context,
		#[Str('register')] string $action,
	): void {
		$death = $this->getDeath($context->char->name);
		if (isset($death)) {
			$context->reply('Your character is already registered in the current death counter.');
			return;
		}
		$this->registerDeathCharacter($context->char->name);
		$context->reply('Thank you for registering. And now go hunting.');
	}

	/** Show the top number of deaths. The default is top 10 */
	#[NCA\HandlesCommand('death')]
	public function deathTopCommand(
		CmdContext $context,
		#[Str('top')] string $action,
		#[SpaceOptional] ?int $num,
	): void {
		$num ??= 10;
		$topDeaths = $this->db->table(Death::getTable())
			->orderByDesc('counter')
			->limit($num)
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/** Show the top number of deaths. The default is top 10 */
	#[NCA\HandlesCommand('death')]
	public function deathTopAllCommand(
		CmdContext $context,
		#[Str('top')] string $action,
		#[Str('all')] string $subAction,
	): void {
		$topDeaths = $this->db->table(Death::getTable())
			->orderByDesc('counter')
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/** Reset your death counter to 0 */
	#[NCA\HandlesCommand('death')]
	public function deathResetCommand(
		CmdContext $context,
		#[Str('reset')] string $action,
	): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			$context->reply("Your character isn't registered in the current death counter.");
			return;
		}
		$death->counter = 0;
		$this->db->update($death);
		$context->reply('Death counter reset to 0.');
	}

	/** Remove yourself from the current death counting */
	#[NCA\HandlesCommand('death')]
	public function deathUnregisterCommand(
		CmdContext $context,
		#[Str('unregister')] string $action,
	): void {
		$death = $this->getDeath($context->char->name);
		if (!isset($death)) {
			$context->reply("Your character isn't registered in the current death counter.");
			return;
		}
		$this->db->table(Death::getTable())
			->where('character', $context->char->name)
			->delete();
		$context->reply('Character removed from counting.');
	}

	/** Restart the death list and unregister everyone */
	#[NCA\HandlesCommand('death restart')]
	public function deathWipeCommand(
		CmdContext $context,
		#[Str('restart')] string $action,
	): void {
		$this->db->table(Death::getTable())->truncate();
		$context->reply('Death list completely wiped and everyone unregistered.');
	}

	/** Reset everyone on the death list to 0 deaths */
	#[NCA\HandlesCommand('death restart')]
	public function deathRestartCommand(
		CmdContext $context,
		#[Str('wipe')] string $action,
	): void {
		$this->db->table(Death::getTable())->update(['counter' => 0]);
		$context->reply('Death list wiped and everyone set to 0 deaths.');
	}

	/**
	 * Show the top number of deaths for the sum of all characters of a player.
	 * The default is top 10
	 */
	#[NCA\HandlesCommand('death')]
	public function deathTopPlayersCommand(
		CmdContext $context,
		#[Str('players')] string $subAction,
		#[Str('top')] string $action,
		#[SpaceOptional] ?int $num,
	): void {
		$num ??= 10;
		$query = $this->db->table(Death::getTable(), 'd')
			->leftJoin(Alt::getTable(as: 'a'), 'd.character', 'a.alt');
		$topDeaths = $query->groupByRaw($query->colFunc('COALESCE', ['a.main', 'd.character']))
			->selectRaw($query->colFunc('COALESCE', ['a.main', 'd.character'], 'character'))
			->selectRaw($query->colFunc('SUM', 'counter', 'counter'))
			->limit($num)
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	/**
	 * Show the top number of deaths for the sum of all characters of a player.
	 * The default is top 10
	 */
	#[NCA\HandlesCommand('death')]
	public function deathTopAllPlayersCommand(
		CmdContext $context,
		#[Str('players')] string $subAction,
		#[Str('top')] string $action,
		#[Str('all')] string $all,
	): void {
		$query = $this->db->table(Death::getTable(), 'd')
			->leftJoin(Alt::getTable(as: 'a'), 'd.character', 'a.alt');
		$topDeaths = $query->groupByRaw($query->colFunc('COALESCE', ['a.main', 'd.character']))
			->selectRaw($query->colFunc('COALESCE', ['a.main', 'd.character'], 'character'))
			->selectRaw($query->colFunc('SUM', 'counter', 'counter'))
			->asObj(Death::class);
		$context->reply($this->renderTopDeaths($topDeaths));
	}

	#[NCA\HandlesCommand('deathmsg')]
	/** List all custom death messages */
	public function listDeathMessages(CmdContext $context): void {
		$lines = $this->db->table(Fun::getTable())
			->where('type', 'death-custom')
			->asObj(Fun::class)
			->map(static function (Fun $entry) use ($context): string {
				$delLink = Text::makeChatcmd(
					'remove',
					'/tell <myname> ' . $context->getCommand() . ' rem ' . $entry->id
				);
				return "<tab>- [{$delLink}] {$entry->content}";
			});
		if ($lines->isEmpty()) {
			$context->reply(
				'No custom death messages defined. Use <highlight><symbol>'.
				$context->getCommand() . ' add &lt;death message&gt;<end> to add one.'
			);
			return;
		}
		$msg = Text::makeBlob(
			'Defined custom death messages',
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
	#[NCA\HandlesCommand('deathmsg')]
	#[NCA\Help\Example(command: 'deathmsg add You suck!')]
	#[NCA\Help\Example(command: 'deathmsg add counter<10 These are rookie numbers!')]
	#[NCA\Help\Example(command: 'deathmsg add prof=enf The doc really sucks')]
	#[NCA\Help\Example(command: 'deathmsg add prof!=enf Blame the tank')]
	#[NCA\Help\Example(command: 'deathmsg add main=Nady Again, Nady?')]
	public function addDeathMessage(
		CmdContext $context,
		#[Str('add')] string $action,
		string $deathMessage,
	): void {
		$fun = new Fun(
			type: 'death-custom',
			content: $deathMessage,
		);
		$this->db->insert($fun);
		$context->reply("New death message added as <highlight>{$fun->id}<end>.");
	}

	#[NCA\HandlesCommand('deathmsg')]
	/** Remove a custom death message */
	public function delDeathMessage(
		CmdContext $context,
		#[Remove] string $action,
		PUuid $id,
	): void {
		$id = $id();
		$deleted = $this->db->table(Fun::getTable())
			->where('type', 'death-custom')
			->delete($id);
		if (!$deleted) {
			$context->reply("The death message <highlight>{$id}<end> doesn't exist.");
			return;
		}
		$context->reply("Death message <highlight>{$id}<end> deleted successfully.");
	}

	/**
	 * Get a matching death message for a given death
	 *
	 * @param Death $death The death properties
	 *
	 * @return ?string A matching death message, or null;
	 */
	private function getRandomDeathMessage(Death $death): ?string {
		$data = $this->db->table(Fun::getTable())
			->whereIn('type', ['death', 'death-custom'])
			->whereIlike('content', 'counter=' . $death->counter . ' %')
			->asObjArr(Fun::class);
		if (count($data) === 0) {
			$data = $this->db->table(Fun::getTable())
				->whereIn('type', ['death', 'death-custom'])
				->asObjArr(Fun::class);
		}
		while (count($data) > 0) {
			$key = array_rand($data, 1);

			$deathMsg = $this->deathMsgFits($death, $data[$key]);
			if (isset($deathMsg)) {
				return str_replace('\\n', "\n", $deathMsg);
			}
			unset($data[$key]);
		}
		return null;
	}

	/**
	 * Check if a given death message applies to a given death
	 *
	 * @param Death $death The death properties
	 * @param Fun   $fun   The Fun object with the death message
	 *
	 * @return ?string Either the death message, or null if it doesn't apply
	 */
	private function deathMsgFits(Death $death, Fun $fun): ?string {
		$parts = explode(' ', $fun->content, 2);
		if (count($parts) < 2) {
			return $fun->content;
		}
		$tokens = preg_split('/(!=|[=<>])/', $parts[0], 2, \PREG_SPLIT_DELIM_CAPTURE);
		if (count($tokens) < 3) {
			return $fun->content;
		}

		$matches = $this->matchesDeathCheck($tokens[0], $tokens[1], $tokens[2], $death);
		if ($matches) {
			return $parts[1];
		}
		return null;
	}

	/**
	 * Check if the given death-check applies to the player
	 *
	 * @param string $token The token to check (main, name, prof)
	 * @param string $value The value to check against
	 * @param Death  $death The death properties
	 *
	 * @return bool true (matches) or false (doesn't match)
	 */
	private function matchesDeathCheck(string $token, string $operator, string $value, Death $death): bool {
		$comparison = match ($operator) {
			'>' => static fn (mixed $a, mixed $b): bool => $a > $b,
			'<' => static fn (mixed $a, mixed $b): bool => $a < $b,
			'!=' => static fn (mixed $a, mixed $b): bool => $a !== $b,
			default => static fn (mixed $a, mixed $b): bool => $a === $b,
		};
		switch ($token) {
			case 'main':
				return $comparison($this->altsController->getMainOf($death->character), ucfirst(strtolower($value)));
			case 'name':
			case 'char':
			case 'charname':
			case 'character':
				return $comparison($death->character, ucfirst(strtolower($value)));
			case 'count':
			case 'counter':
				return $comparison($death->counter, (int)$value);
		}

		$player = $this->playerManager->byName($death->character);
		if (!isset($player)) {
			return false;
		}
		return match ($token) {
			'prof','profession' => $comparison($player->profession?->is($value) ?? false, true),
			'faction','side' => $comparison(strtolower($player->faction->value), strtolower($value)),
			'gender','sex' => $comparison(strtolower($player->gender), strtolower($value)),
			'race','breed' => $comparison(strtolower($player->breed), strtolower($value)),
			'level','lvl' => $comparison($player->level, (int)$value),
			default => true,
		};
	}

	/**
	 * Render the top deaths
	 *
	 * @param Collection<int,Death> $topDeaths
	 */
	private function renderTopDeaths(Collection $topDeaths): string {
		if ($topDeaths->isEmpty()) {
			return 'No one has registered for dying yet.';
		}
		$maxDeaths = $topDeaths->max('counter');
		$text = 'The top ' . $topDeaths->count() . ' deaths';
		$blob = "<header2>{$text}<end>\n";
		$blob .= $topDeaths->map(static function (Death $death) use ($maxDeaths): string {
			return Text::alignNumber($death->counter, strlen((string)$maxDeaths)).
				"<tab>{$death->character}";
		})->join("\n");
		return Text::makeBlob($text, $blob);
	}
}
