<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use function Safe\preg_match;

class PTowerSite extends Base {
	public string $pf;
	public int $site;
	protected static string $regExp = "[0-9A-Za-z]+[A-Za-z]{1,3}\s*\d+";
	protected string $value;

	public function __construct(string $value) {
		preg_match("/^([0-9A-Za-z]+[A-Za-z])\s*(\d+)$/", $value, $matches);
		$this->pf = strtoupper($matches[1]);
		$this->site = (int)$matches[2];
		$this->value = "{$this->pf} {$this->site}";
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	public static function getExample(): ?string {
		return "&lt;tower site&gt;";
	}
}
