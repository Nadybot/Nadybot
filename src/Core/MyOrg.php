<?php declare(strict_types=1);

namespace Nadybot\Core;

use AO\Utils;
use Nadybot\Core\Attributes\Instance;

/** This represents the org of this bot, and a cache of its members */
#[Instance]
class MyOrg {
	/**
	 * The rank for each member of this bot's guild/org
	 * [(string)name => (int)rank]
	 *
	 * @var array<string,int>
	 */
	private array $orgMembers = [];

	/** Is the given character a member of our organization? */
	public function isMember(string $character): bool {
		$character = Utils::normalizeCharacter($character);
		return isset($this->orgMembers[$character]);
	}

	/** Set a character's org-rank */
	public function setMemberLevel(string $character, int $value): int {
		$character = Utils::normalizeCharacter($character);
		return $this->orgMembers[$character] = $value;
	}

	/** Get the org-rank of a member, or `null` if not a member */
	public function getMemberLevel(string $character): ?int {
		$character = Utils::normalizeCharacter($character);
		return $this->orgMembers[$character] ?? null;
	}

	/**
	 * Get the rank for each member of this bot's org
	 *
	 * @return array<string,int> `[(string)name => (int)rank]`
	 */
	public function getMembers(): array {
		return $this->orgMembers;
	}

	/** Remove a member from this org's list */
	public function delMember(string $character): void {
		$character = Utils::normalizeCharacter($character);
		unset($this->orgMembers[$character]);
	}

	/** Remove all cached org members */
	public function clearMembers(): void {
		$this->orgMembers = [];
	}
}
