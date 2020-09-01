<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use Nadybot\Core\JSONDataModel;

class Stream extends JSONDataModel {
	public string $id;
	public string $url;
	public int $bitrate;
	public string $codec;
}
