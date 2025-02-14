<?php declare(strict_types=1);

namespace Nadybot\Core;

use AO\Utils;
use Nadybot\Core\Attributes\Instance;

#[Instance]
class MyOrg {
	/**
	 * The rank for each member of this bot's guild/org
	 * [(string)name => (int)rank]
	 *
	 * @var array<string,int>
	 */
	private array $orgMembers = [];

	public function isMember(string $character): bool {
		$character = Utils::normalizeCharacter($character);
		return isset($this->orgMembers[$character]);
	}

	public function setMemberLevel(string $character, int $value): int {
		$character = Utils::normalizeCharacter($character);
		return $this->orgMembers[$character] = $value;
	}

	public function getMemberLevel(string $character): ?int {
		$character = Utils::normalizeCharacter($character);
		return $this->orgMembers[$character] ?? null;
	}

	/** @return array<string,int> */
	public function getMembers(): array {
		return $this->orgMembers;
	}

	public function delMember(string $character): void {
		$character = Utils::normalizeCharacter($character);
		unset($this->orgMembers[$character]);
	}

	public function clearMembers(): void {
		$this->orgMembers = [];
	}
}
