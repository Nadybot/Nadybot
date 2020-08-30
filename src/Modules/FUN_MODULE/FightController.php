<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 * @author Mdkdoc420 (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'fight',
 *		accessLevel = 'all',
 *		description = 'Let two people fight against each other',
 *		help        = 'fun_module.txt'
 *	)
 */
class FightController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/**
	 * @HandlesCommand("fight")
	 * @Matches("/^fight (.+) vs (.+)$/i")
	 * @Matches("/^fight (.+) (.+)$/i")
	 */
	public function fightCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$player1 = ucfirst(strtolower($args[1]));
		$player2 = ucfirst(strtolower($args[2]));

		// Checks if user is trying to get Chuck Norris to fight another Chuck Norris
		if ($this->isChuckNorris($player1) && $this->isChuckNorris($player2)) {
			$msg = "Theres only enough room in this world for one Chuck Norris!";
			$sendto->reply($msg);
			return;
		}

		// This checks if the user is trying to get two of the same people fighting each other
		if (strcasecmp($player1, $player2) === 0) {
			$twin = [
				"Dejavu?",
				"$player1 can't fight $player2, it may break the voids of space and time!",
				"As much as I'd love to see $player1 punching himself/herself in the face, it just isn't theoretical..."];

			$sendto->reply($this->util->randomArrayValue($twin));
			return;
		}

		$fighter1 = $this->getFighter($player1);
		$fighter2 = $this->getFighter($player2);

		$list = "Fight <highlight>$player1<end> VS <highlight>$player2<end> \n\n";
		while ($fighter1->hp > 0 && $fighter2->hp > 0) {
			$list .= $this->doAttack($fighter1, $fighter2);
			$list .= $this->doAttack($fighter2, $fighter1);
			$list .= "\n";
		}

		if ($fighter1->hp > $fighter2->hp) {
			$list .= "\nAnd the winner is …… <highlight>$player1!<end>";
			$msg = $this->text->makeBlob("$player1 vs $player2: $player1 wins!", $list);
		} elseif ($fighter2->hp > $fighter1->hp) {
			$list .= "\nAnd the winner is …… <highlight>$player2!<end>";
			$msg = $this->text->makeBlob("$player1 vs $player2: $player2 wins!", $list);
		} else {
			$list .= "\nIt's a tie!!";
			$msg = $this->text->makeBlob("$player1 vs $player2: It's a tie!", $list);
		}

		$sendto->reply($msg);
	}

	public function getFighter($name): Fighter {
		$weaponNames = [
			"a nerfstick",
			"bad breath",
			"spaghetti code",
			"glass cannon",
			"a yet unknown nano",
			"bare fists",
			"a mechanical keyboard",
			"a deadly joke",
			"a very mean-tempered leet",
			"an invalid opcode",
			"a floating ghost light",
			"a thrown shoe",
			"a bottle of pisco",
			"a fight macro",
			"a bunch of pets",
			"a badly drawn planet map",
			"a mean-looking cheezeburger",
			"an illegally modified damage dice",
		];
		$fighter = new Fighter();
		$fighter->name = $name;
		if ($this->isChuckNorris($name)) {
			$fighter->weapon = "round house kick";
			$fighter->minDamage = 4001;
			$fighter->maxDamage = 6000;
			$fighter->hp = 20000;
		} else {
			$fighter->weapon = $this->util->randomArrayValue($weaponNames);
			$fighter->minDamage = 1000;
			$fighter->maxDamage = 4000;
			$fighter->hp = 20000;
		}
		return $fighter;
	}

	public function doAttack(Fighter $attacker, Fighter $defender): string {
		$dmg = rand($attacker->minDamage, $attacker->maxDamage);
		if ($this->isCriticalHit($attacker, $dmg)) {
			$crit = " <red>Critical Hit!<end>";
		} else {
			$crit = "";
		}

		$defender->hp -= $dmg;
		return "<highlight>{$attacker->name}<end> hit <highlight>{$defender->name}<end> with {$attacker->weapon} for $dmg dmg.$crit\n";
	}

	public function isCriticalHit(Fighter $fighter, int $dmg): bool {
		return ($dmg / $fighter->maxDamage) > 0.9;
	}

	public function isChuckNorris(string $name): bool {
		$name = strtolower($name);
		return $name === "chuck" || $name === "chuck norris" || $name === "chucknorris";
	}
}
