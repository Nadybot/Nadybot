<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use DateTimeInterface;
use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

/**
 * This represents a single player in the online list
 *
 * @package Nadybot\Modules\ONLINE_MODULE
 */
#[NCA\DB\Table(name: 'online_hide')]
class OnlineHide extends DBTable {
	/** The artificial ID of this hide mask */
	#[NCA\DB\PK] public UuidInterface $id;

	/** Time and date when this mask was created */
	public DateTimeInterface $created_on;

	/**
	 * @param string             $mask       A glob mask that will match one or more names
	 * @param string             $created_by Name of the character who hid this mask
	 * @param ?DateTimeInterface $created_on Time and date when this mask was created
	 * @param ?UuidInterface     $id         The artificial ID of this hide mask
	 */
	public function __construct(
		public string $mask,
		public string $created_by,
		?DateTimeInterface $created_on=null,
		?UuidInterface $id=null,
	) {
		$this->created_on = $created_on ?? new DateTimeImmutable();
		$this->id = $id ?? Uuid::uuid7($created_on);
	}
}
