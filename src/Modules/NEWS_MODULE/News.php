<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

class News extends NewNews {
	/** The internal ID of this news entry */
	public int $id;

	public static function fromNewNews(NewNews $news): self {
		$result = new self();
		foreach (get_object_vars($news) as $key => $value) {
			$result->{$key} = $value;
		}
		return $result;
	}
}
