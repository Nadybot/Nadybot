<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\MessageEmitter;
use Nadybot\Core\Routing\Source;

class CloakChannel implements MessageEmitter {
	public function getChannelName(): string {
		return Source::SYSTEM . "(cloak)";
	}
}
