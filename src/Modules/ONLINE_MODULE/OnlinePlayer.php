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
	/** The AFK message of the player or an empty string */
	#[JSON\Name('afk_message')]
	public string $afk = '';

	/**
	 * The name of the main character, or the same as $name if
	 * this is the main character of the player
	 */
	#[JSON\Name('main_character')]
	public string $pmain;

	/** The nickname of the main character, or null if unset */
	#[JSON\Name('nickname')]
	public ?string $nick = null;

	/** True if this player is currently online, false otherwise */
	public bool $online = false;

	public static function fromPlayer(?Player $player=null, ?Online $online=null): static {
		if (!isset($player) && !isset($online)) {
			throw new \InvalidArgumentException(__CLASS__ . '::' . __FUNCTION__ . '() requires at least onr of $player or $online');
		}
		if (!isset($player)) {
			$player = new Player(
				charid: 0,
				name: $online->name,
			);
		}
		$op = new static(...get_object_vars($player));
		if (isset($online)) {
			$op->online = true;
			$op->name = $online->name;
			$op->afk = $online->afk ?? '';
		}
		$op->pmain = $player->name;
		return $op;
	}
}
