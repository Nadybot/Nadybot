<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\Attributes\DB\{MapRead, PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'nanos', shared: Shared::Yes)]
class Nano extends DBTable {
	/**
	 * @param string[] $professions
	 *
	 * @psalm-param list<string> $professions
	 */
	public function __construct(
		#[PK] public int $nano_id,
		public int $ql,
		public string $nano_name,
		public string $school,
		public string $strain,
		public int $strain_id,
		public string $sub_strain,
		#[MapRead([self::class, 'parseProfessions'])] public array $professions,
		public string $location,
		public int $nano_cost,
		public bool $froob_friendly,
		public int $sort_order,
		public bool $nano_deck,
		public ?int $crystal_id=null,
		public ?string $crystal_name=null,
		public ?int $min_level=null,
		public ?int $spec=null,
		public ?int $mm=null,
		public ?int $bm=null,
		public ?int $pm=null,
		public ?int $si=null,
		public ?int $ts=null,
		public ?int $mc=null,
	) {
	}

	public function getRequirement(NanoSkill $skill): ?int {
		return match ($skill) {
			NanoSkill::MM => $this->mm,
			NanoSkill::BM => $this->bm,
			NanoSkill::PM => $this->pm,
			NanoSkill::SI => $this->si,
			NanoSkill::TS => $this->ts,
			NanoSkill::MC => $this->mc,
		};
	}

	public function getCrystalLink(?string $text=null): ?string {
		if (!isset($this->crystal_id)) {
			return null;
		}
		$ql = $this->ql;
		$text ??= $this->crystal_name ?? 'Crystal';
		return "<a href='itemref://{$this->crystal_id}/{$this->crystal_id}/{$ql}'>{$text}</a>";
	}

	/** @return list<string> */
	public static function parseProfessions(string $professions): array {
		return explode(':', $professions);
	}
}
