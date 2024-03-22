<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use Nadybot\Core\{Registry, Util};

class PProfession extends Base {
	protected static string $regExp = 'adv(|y|enturer)'.
		'|age(nt)?'.
		'|(bureau)?crat'.
		'|doc(tor)?'.
		'|enf(o|orcer)?'.
		'|eng([iy]|ineer)?'.
		'|fix(er)?'.
		'|keep(er)?'.
		'|ma(rtial( ?artist)?)?'.
		'|mp|meta(-?physicist)?'.
		'|nt|nano(-?technician)?'.
		'|sol(d|dier)?'.
		'|tra(d|der)?'.
		'|sha(de)?';
	protected string $value;

	public function __construct(string $value) {
		/** @var ?Util */
		$util = Registry::getInstance(Util::class);
		if (isset($util)) {
			$this->value = $util->getProfessionName($value);
		} else {
			$this->value = $value;
		}
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
