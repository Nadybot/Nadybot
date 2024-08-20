<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};

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
	)
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
	): void {
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
		if ($death->counter === 1) {
			$deathText = [
				"You didn't even start yet...",
				"There's a first time for everything.",
				"A magic dwells in each beginning.",
				"This is how it all started",
				"Congrats, you've officially lost your noob status!",
				"Ah, the sweet taste of your first respawn!",
				"Don't worry, it only gets worse from here.",
				"First time dying? Well, now you've got experience... in failing!",
			];
		} elseif ($death->counter === 9) {
			$deathText = [
				"Only 3 more, and you have a dozen!",
			];
		} else {
			$deathText = [
				"Dun dun dun dun, and another one bites the dust.",
				"And another one gone, and another one gone, another one bites the dust.",
				"How do you think I'm gonna get along without you, when you're gone?",
				"On behalf of the entire team, please accept our deepest sympathies.",
				"Blame the doc!",
				"I think the tank sucks. Go tell them!",
				"Our hearts go out to you during this time of sorrow.",
				"I hold you close in my thoughts at this sad time.",
				"I'd like to express our sincere condolences to you and your team.",
				"You and your whole team are in my thoughts.",
				"He's dead, Jim!",
				"Team, you have my deepest condolences for the loss of someone so dear.",
				"Words seem inadequate, so I send this flower as a token of my great sympathy\n".
				"<tab><green>--,--`-<end><red>@<end>",
				"Today and always, may loving memories bring you strength, peace, and fast rezzing.",
				"Sending heartfelt condolences",
				"Someone so special will never be forgotten. Except by the tank. And the doc.",
				"Geez, reclaim takes forever...",
				"Whoops-a-daisy",
				"R U nubi?".
				"Did you trip over your own pixels?",
				"I've seen NPCs with better survival instincts.",
				"Respawn faster than your reaction time!",
				"Was that a tactical faceplant?",
				"Need a tutorial on dodging?",
				"Did you just uninstall mid-fight?",
				"At least your gear died with dignity.",
				"I didn't know you were speedrunning to the reclaim.",
				"Is the reclaim your new home address?",
				"Remember, it's all in good fun!",
				"I did not pull my gun!",
				"Newland is that way!",
				"Like a punk!",
				"Well, here we are. At reclaim. Again.",
				"It was just a single rollerrat and you weren't ready for it...",
				"Was that lag or just your reflexes?",
				"Holy sh***",
				"The escape route was the opposite direction of the big red beast.",
			];
			if ($death->counter < 10) {
				$deathText = array_merge($deathText, [
					"These are rookie numbers. Go and die some more!",
					"Keep up the good work, you're getting the hang of it!",
				]);
			}
		}
		$randomLine = $this->util->randomArrayValue($deathText);
		$text = $this->text->renderPlaceholders(
			$this->deathCounterDisplay,
			[
				'counter' => $death->counter,
				'name' => $death->character,
				'text' => $randomLine,
			]
		);
		$context->reply($text);
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
}
