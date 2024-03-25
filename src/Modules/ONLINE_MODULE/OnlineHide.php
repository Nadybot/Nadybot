<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Safe\DateTime;

/**
 * This represents a single player in the online list
 *
 * @package Nadybot\Modules\ONLINE_MODULE
 */
class OnlineHide extends DBRow {
	/** Time and date when this mask was created */
	public DateTime $created_on;

	/**
	 * @param string    $mask       A glob mask that will match one or more names
	 * @param string    $created_by Name of the character who hid this mask
	 * @param ?DateTime $created_on Time and date when this mask was created
	 * @param ?int      $id         The artificial ID of this hide mask
	 */
	public function __construct(
		public string $mask,
		public string $created_by,
		?DateTime $created_on=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		$this->created_on = $created_on ?? new DateTime();
	}
}
