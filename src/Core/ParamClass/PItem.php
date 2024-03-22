<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use InvalidArgumentException;
use Nadybot\Core\Safe;

class PItem extends Base {
	public int $lowID;
	public int $highID;
	public int $ql;
	public string $name;
	protected static string $regExp = "(?:<|&lt;)a href=(?:&#39;|'|\x22)itemref://\d+/\d+/\d+(?:&#39;|'|\x22)(?:>|&gt;).+?(<|&lt;)/a(>|&gt;)";
	protected string $value;

	public function __construct(string $value) {
		$this->value = htmlspecialchars_decode($value);
		if (!count($matches = Safe::pregMatch("{itemref://(\d+)/(\d+)/(\d+)(?:&#39;|'|\x22)(?:>|&gt;)(.+?)(<|&lt;)/a(>|&gt;)}", $value))) {
			throw new InvalidArgumentException('Item is not matching the item spec');
		}
		$this->lowID = (int)$matches[1];
		$this->highID = (int)$matches[2];
		$this->ql = (int)$matches[3];
		$this->name = $matches[4];
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
