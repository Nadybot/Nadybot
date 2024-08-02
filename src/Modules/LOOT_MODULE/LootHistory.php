<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'loot_history')]
class LootHistory extends DBTable {
	/** Artificial primary key */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param int            $roll      The n-th roll on this bot
	 * @param int            $pos       Position on the loot table (starting with 1)
	 * @param string         $name      Simple name (without HTML) of the item
	 * @param int            $dt        Unix timestamp when it was rolled
	 * @param string         $added_by  Who added this item to the loot table
	 * @param string         $rolled_by Who did the !flatroll
	 * @param int            $amount    How many items of this in total were rolled in this slot
	 * @param ?int           $icon      Funcom aodb icon id
	 * @param string         $display   Nice display (including item link) of the item
	 * @param string         $comment   Optional comment about the item
	 * @param ?string        $winner    If someone won the item, name of the winner
	 * @param ?UuidInterface $id        Artificial primary key
	 * @param string[]       $winners
	 *
	 * @psalm-param list<string> $winners
	 */
	public function __construct(
		public int $roll,
		public int $pos,
		public string $name,
		public int $dt,
		public string $added_by,
		public string $rolled_by,
		public int $amount=1,
		public ?int $icon=null,
		public string $display='',
		public string $comment='',
		public ?string $winner=null,
		?UuidInterface $id=null,
		#[NCA\DB\Ignore] public array $winners=[],
	) {
		$this->id = $id ?? Uuid::uuid7((new DateTimeImmutable())->setTimestamp($dt));
	}
}
