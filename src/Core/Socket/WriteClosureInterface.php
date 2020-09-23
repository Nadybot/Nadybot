<?php declare(strict_types=1);

namespace Nadybot\Core\Socket;

interface WriteClosureInterface {
	public function exec(AsyncSocket $socket): ?bool;
	public function allowReading(): bool;
}
