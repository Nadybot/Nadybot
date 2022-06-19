<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use DateTime;
use Nadybot\Core\DBRow;

/**
 * This represents a single player in the online list
 *
 * @package Nadybot\Modules\ONLINE_MODULE
 */
class OnlineHide extends DBRow {
	/** The artificial ID of this hide mask */
	public int $id;

	/** A glob mask that will match one or more names */
	public string $mask;

	/** Name of the character who hid this mask */
	public string $created_by;

	/** Time and date when this mask was created */
	public DateTime $created_on;

	public function __construct() {
		$this->created_on = new DateTime();
	}
}
