<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Interfaces;

interface CounterProvider extends ValueProvider {
	public function getValue(): int;
}
