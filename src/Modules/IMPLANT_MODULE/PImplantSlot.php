<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\ParamClass\Base;

class PImplantSlot extends Base {
	protected static string $regExp = 'head|eyes?|ear|rarm|chest|larm|rwrist|waist|lwrist|rhand|legs?|lhand|feet|foot|brain|body';
	protected string $value;

	/** @var array<string,string> */
	private array $aliases = [
		'eyes' => 'eye',
		'foot' => 'feet',
		'brain' => 'head',
		'body' => 'chest',
		'leg' => 'legs',
	];

	public function __construct(string $value) {
		$this->value = strtolower($this->aliases[strtolower($value)]??$value);
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
