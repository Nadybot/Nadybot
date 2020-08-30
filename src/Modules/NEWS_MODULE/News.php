<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\DBRow;

class News extends DBRow {
	public int $id;
	public int $time;
	public string $name;
	public string $news;
	public bool $sticky;
	public bool $deleted;
}
