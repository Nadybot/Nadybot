<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\DBRow;

class SilenceCmd extends DBRow {
	public string $cmd;
	public string $channel;
}
