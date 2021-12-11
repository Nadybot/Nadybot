<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Geometry extends JSONDataModel {
	public string $type;
	/**
	 * @var array<float|int>
	 * @psalm-var array{0: float, 1: float, 2: int}
	 */
	public array $coordinates;
}
