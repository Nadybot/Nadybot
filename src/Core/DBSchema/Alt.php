<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

/** This represents a single alt of a player */
#[Table(name: 'alts', shared: Shared::Yes)]
class Alt extends DBTable {
	/**
	 * @param string      $alt               Name of the alt character
	 * @param string      $main              name of the main character
	 * @param null|bool   $validated_by_main Has this alt been validated by the main character?
	 * @param null|bool   $validated_by_alt  Has this alt been validated by the alt character?
	 * @param null|string $added_via         Name of the bot that added this alt/main-combination
	 */
	public function __construct(
		#[PK] public string $alt,
		public string $main,
		public ?bool $validated_by_main=false,
		public ?bool $validated_by_alt=false,
		public ?string $added_via=null,
	) {
	}
}
