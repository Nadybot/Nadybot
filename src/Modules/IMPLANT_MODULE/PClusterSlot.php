<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\ParamClass\Base;

class PClusterSlot extends Base {
	protected static string $regExp = 'shiny|bright|faded|symbiant|symb';

	/** @var 'shiny'|'bright'|'faded'|'symb' */
	protected string $value;

	public function __construct(string $value) {
		$value = strtolower($value);
		if ($value === 'symbiant') {
			$this->value = 'symb';
		} else {
			assert($value === 'shiny' || $value === 'bright' || $value === 'faded' || $value === 'symb');
			$this->value = $value;
		}
	}

	/** @return 'shiny'|'bright'|'faded'|'symb' */
	public function __invoke(): string {
		return $this->value;
	}

	/** @return 'shiny'|'bright'|'faded'|'symb' */
	public function __toString(): string {
		return $this->value;
	}

	public static function getExample(): string {
		return 'shiny|bright|faded|symb';
	}
}
