<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\DBRow;

class Comment extends DBRow {
	/** The internal id of the comment */
	public int $id;

	/** About whom the comment is */
	public string $character;

	/** Who created the comment? */
	public string $created_by;

	/** Unix timestamp when the comment was created */
	public int $created_at;

	/** Category of the comment */
	public string $category;

	/** The actual comment */
	public string $comment = '';

	public function __construct() {
		$this->created_at = time();
	}
}
