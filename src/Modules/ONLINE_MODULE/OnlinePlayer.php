<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\Attributes\JSON;
use Nadybot\Core\DBSchema\Player;

/**
 * This represents a single player in the online list
 *
 * @package Nadybot\Modules\ONLINE_MODULE
 */
class OnlinePlayer extends Player {
	/**
	 * The AFK message of the player or an empty string
	 */
	#[JSON\Name("afk_message")]
	public string $afk = '';

	/**
	 * The name of the main character, or the same as $name if
	 * this is the main character of the player
	 */
	#[JSON\Name("main_character")]
	public string $pmain;

	/** True if this player is currently online, false otherwise */
	public bool $online = false;

	final public function __construct() {
	}

	public static function fromPlayer(?Player $player=null, ?Online $online=null): static {
		$op = new static();
		if (isset($player)) {
			foreach (get_object_vars($player) as $key => $value) {
				$op->{$key} = $value;
			}
		}
		if (isset($online)) {
			$op->online = true;
			$op->name = $online->name;
			$op->afk = $online->afk ?? "";
		}
		return $op;
	}
}
