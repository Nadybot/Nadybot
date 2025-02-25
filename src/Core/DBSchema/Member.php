<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};

/** This table manages who has member privileges on the bot */
#[NCA\DB\Table(name: 'members')]
class Member extends DBTable {
	public int $joined;

	/**
	 * @param string      $name     Name of the character
	 * @param int         $autoinv  Automatically invite the character to our private
	 *                              channel when they log in?
	 * @param null|int    $joined   Unix time stamp when the character became a member, or
	 *                              `null` if unknown
	 * @param null|string $added_by Character name who added this character to
	 *                              the bot, or `null` if unknown
	 */
	public function __construct(
		#[NCA\DB\PK] public string $name,
		public int $autoinv=0,
		?int $joined=null,
		public ?string $added_by=null,
	) {
		$this->joined = $joined ?? time();
	}
}
