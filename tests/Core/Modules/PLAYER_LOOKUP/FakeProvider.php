<?php declare(strict_types=1);

namespace Nadybot\Tests\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\{
	CharacterProvider,
	DirectoryFeature,
	OrgRosterProvider,
	OrgRosterResult,
};

/** A provider that only exists to test which providers are chosen */
class FakeProvider implements CharacterProvider, OrgRosterProvider {
	/**
	 * @param list<int>              $dimensions
	 * @param list<DirectoryFeature> $features
	 */
	public function __construct(
		private string $id,
		private string $name,
		private array $dimensions,
		private array $features,
	) {
	}

	public function getId(): string {
		return $this->id;
	}

	public function getName(): string {
		return $this->name;
	}

	public function supportsDimension(int $dimension): bool {
		return in_array($dimension, $this->dimensions, true);
	}

	public function getFeatures(): array {
		return $this->features;
	}

	public function lookupCharacter(string $name, int $dimension): ?Player {
		return null;
	}

	public function lookupOrgRoster(int $orgId, int $dimension, bool $forceUpdate=false): ?OrgRosterResult {
		return null;
	}
}
