<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	ParamClass\PCharacter,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Mdkdoc420 (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'fight',
		accessLevel: 'guest',
		description: 'Let two people fight against each other',
	)
]
class FightController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	/**
	 * Let 2 people fight against each other
	 *
	 * Note: Chuck Norris never loses
	 */
	#[NCA\HandlesCommand('fight')]
	public function fightCommand(
		CmdContext $context,
		PCharacter $player1,
		#[NCA\Str('vs')] ?string $vs,
		PCharacter $player2
	): void {
		$player1 = $player1();
		$player2 = $player2();

		// Checks if user is trying to get Chuck Norris to fight another Chuck Norris
		if ($this->isChuckNorris($player1) && $this->isChuckNorris($player2)) {
			$msg = "There's only enough room in this world for one Chuck Norris!";
			$context->reply($msg);
			return;
		}

		// This checks if the user is trying to get two of the same people fighting each other
		if (strcasecmp($player1, $player2) === 0) {
			$twin = [
				'Déjà vu?',
				"{$player1} can't fight {$player2}, it may break the voids of space and time!",
				"As much as I'd love to see {$player1} punching himself/herself in the face, it just isn't theoretical...", ];

			$context->reply($this->util->randomArrayValue($twin));
			return;
		}

		$fighter1 = $this->getFighter($player1);
		$fighter2 = $this->getFighter($player2);

		$list = "<header2>Fight {$player1} vs {$player2}<end>\n\n";
		while ($fighter1->hp > 0 && $fighter2->hp > 0) {
			$list .= '<tab>' . $this->doAttack($fighter1, $fighter2);
			$list .= '<tab>' . $this->doAttack($fighter2, $fighter1);
			$list .= "\n";
		}

		if ($fighter1->hp > $fighter2->hp) {
			$list .= "\nAnd the winner is …… <highlight>{$player1}!<end>";
			$msg = $this->text->makeBlob("{$player1} vs {$player2}: {$player1} wins!", $list);
		} elseif ($fighter2->hp > $fighter1->hp) {
			$list .= "\nAnd the winner is …… <highlight>{$player2}!<end>";
			$msg = $this->text->makeBlob("{$player1} vs {$player2}: {$player2} wins!", $list);
		} else {
			$list .= "\nIt's a tie!!";
			$msg = $this->text->makeBlob("{$player1} vs {$player2}: It's a tie!", $list);
		}

		$context->reply($msg);
	}

	public function getFighter(string $name): Fighter {
		$weaponNames = [
			'with a nerfstick' => 'nerfed damage',
			'with bad breath' => 'disease damage',
			'with spaghetti code' => 'headache damage',
			'with a glass cannon build' => 'odd damage types',
			'with a yet unknown nano' => 'illegal nano damage',
			'with bare fists' => 'melee damage',
			'with a mechanical keyboard' => 'iron damage',
			'with a deadly joke' => 'imaginary damage',
			'with a very mean-tempered leet' => 'cuteness damage',
			'with an invalid opcode' => 'coding damage',
			'with a floating ghost light' => 'haunted damage',
			'with a thrown shoe' => 'rubber damage',
			'with a bottle of pisco' => 'alcohol damage',
			'with a fight macro' => 'broken link damage',
			'with a bunch of pets' => 'trample damage',
			'with a badly drawn planet map' => 'brain damage',
			'with an old, used sock' => 'stinky damage',
			'with a mean-looking cheezeburger' => 'cheese damage',
			'with an illegally modified damage dice' => 'illegal damage',
			'with a squid' => 'ink damage',
			'while no one was watching' => 'unknown damage',
			'with a heavy book' => 'intellectual damage',
			'with a paperback book' => 'reading damage',
			'with social media' => 'fake news damage',
			'with facts' => 'science damage',
			"with Schrödinger's cat" => 'unknown damage',
			'in their imagination' => 'imaginary damage',
			'with a protest placard' => 'arguments',
			'with a Funcom petition' => 'patience damage',
			'with a froob-sent Funcom petition' => 'extra patience damage',
		];
		$fighter = new Fighter();
		$fighter->name = $name;
		if ($this->isChuckNorris($name)) {
			$fighter->weapon = 'with a round house kick';
			$fighter->damageType = 'otherworldly damage';
			$fighter->minDamage = 4_001;
			$fighter->maxDamage = 6_000;
			$fighter->hp = 20_000;
		} else {
			$fighter->weapon = $this->util->randomArrayValue(array_keys($weaponNames));
			$fighter->damageType = $weaponNames[$fighter->weapon];
			$fighter->minDamage = 1_000;
			$fighter->maxDamage = 4_000;
			$fighter->hp = 20_000;
		}
		return $fighter;
	}

	public function doAttack(Fighter $attacker, Fighter $defender): string {
		$dmg = rand($attacker->minDamage, $attacker->maxDamage);
		if ($this->isCriticalHit($attacker, $dmg)) {
			$crit = ' <red>Critical Hit!<end>';
		} else {
			$crit = '';
		}

		$defender->hp -= $dmg;
		return "<highlight>{$attacker->name}<end> hits <highlight>{$defender->name}<end> {$attacker->weapon} for {$dmg} {$attacker->damageType}.{$crit}\n";
	}

	public function isCriticalHit(Fighter $fighter, int $dmg): bool {
		return ($dmg / $fighter->maxDamage) > 0.9;
	}

	public function isChuckNorris(string $name): bool {
		$name = strtolower($name);
		return $name === 'chuck' || $name === 'chuck norris' || $name === 'chucknorris';
	}
}
