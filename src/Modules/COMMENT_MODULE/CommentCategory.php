<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\AccessLevel;

#[Table(name: '<table:comment_categories>')]
class CommentCategory extends DBTable {
	/** Unix timestamp when the category was created */
	public int $created_at;

	/**
	 * @param ?int        $created_at   Unix timestamp when the category was created
	 * @param string      $name         The name of the category
	 * @param string      $created_by   Name of the character who created the category
	 * @param AccessLevel $min_al_read  The minimum access level required to read comments of this category
	 * @param AccessLevel $min_al_write The minimum access level required to write comments of this category
	 * @param bool        $user_managed Whether the category is from the system (false) or from a user (true)
	 */
	public function __construct(
		?int $created_at=null,
		#[PK] public string $name='unknown',
		public string $created_by='Unknown',
		public AccessLevel $min_al_read=AccessLevel::All,
		public AccessLevel $min_al_write=AccessLevel::All,
		public bool $user_managed=true,
	) {
		$this->created_at = $created_at ?? time();
	}
}
