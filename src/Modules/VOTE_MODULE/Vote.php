<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\DBRow;

class Vote extends DBRow {
	public int $poll_id;
	public string $author;
	public string $answer;
	public int $time;
}
