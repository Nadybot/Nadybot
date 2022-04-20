<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Routing\Source;

class WebSource extends Source {
	public ?string $renderAs;
	public string $color;
}
