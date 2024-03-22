<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\ParamClass\Base;

class PBotType extends Base {
	protected static string $regExp = 'strong|supple|enduring|observant|arithmetic|spiritual';
	protected string $value;

	public function __construct(string $value) {
		$this->value = ucfirst(strtolower($value));
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
