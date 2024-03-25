<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\DBRow;

class CommentCategory extends DBRow {
	/** Unix timestamp when the category was created */
	public int $created_at;

	/**
	 * @param ?int   $created_at   Unix timestamp when the category was created
	 * @param string $name         The name of the category
	 * @param string $created_by   Name of the character who created the category
	 * @param string $min_al_read  The minimum access level required to read comments of this category
	 * @param string $min_al_write The minimum access level required to write comments of this category
	 * @param bool   $user_managed Whether the category is from the system (false) or from a user (true)
	 */
	public function __construct(
		?int $created_at=null,
		public string $name='unknown',
		public string $created_by='Unknown',
		public string $min_al_read='all',
		public string $min_al_write='all',
		public bool $user_managed=true,
	) {
		$this->created_at = $created_at ?? time();
	}
}
