<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

class News extends NewNews {
	/** The internal ID of this news entry */
	public int $id;

	public static function fromNewNews(NewNews $news): self {
		$result = new self();
		foreach (get_class_vars(NewNews::class) as $key) {
			$result->{$key} = $news->{$key};
		}
		return $result;
	}
}
