<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use Nadybot\Core\JSONDataModel;

class Song extends JSONDataModel {
	public string $date;
	public string $artist = "Unknown Artist";
	public string $title = "Unknown Song";
	public string $artwork;
	public int $duration;
}
