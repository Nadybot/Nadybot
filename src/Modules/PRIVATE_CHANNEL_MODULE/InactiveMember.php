<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\DBSchema\LastOnline;

class InactiveMember {
	public string $name;
	public ?LastOnline $last_online=null;
}
