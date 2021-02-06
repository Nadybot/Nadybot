<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\DBRow;

class CommentCategory extends DBRow {
	/** The name of the category */
	public string $name = "unknown";

	/** Name of the character who created the category */
	public string $created_by = "Unknown";

	/** Unix timestamp when the category was created */
	public int $created_at;

	/** The minimum access level required to read comments of this category */
	public string $min_al_read = 'all';

	/** The minimum access level required to write comments of this category */
	public string $min_al_write = 'all';

	/** Whether the category is from the system (false) or from a user (true) */
	public bool $user_managed = true;

	public function __construct() {
		$this->created_at = time();
	}
}
