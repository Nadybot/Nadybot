<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\DBSchema\Player;

class Guild {
	public int $guild_id;
	public string $orgname;
	public string $orgside;

	/** Anarchy, Republic, etc. */
	public string $governing_form = 'Anarchy';

	/** @var array<string,Player> */
	public array $members = [];

	/** When was the guild information last updated on PORK */
	public ?int $last_update;

	public function getColorName(): string {
		return '<' . strtolower($this->orgside) . ">{$this->orgname}<end>";
	}
}
