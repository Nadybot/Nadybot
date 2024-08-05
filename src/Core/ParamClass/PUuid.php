<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use function Safe\preg_match;

use Nadybot\Core\Exceptions\UserException;

class PUuid extends Base {
	protected static string $regExp = '[0-9a-fA-F-]+';
	protected string $value;

	public function __construct(string $value) {
		$this->value = strtolower($value);
	}

	public function __invoke(): string {
		return (string)$this;
	}

	public function __toString(): string {
		if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->value)) {
			throw new UserException("<highlight>{$this->value}<end> is not a valid UUID.");
		}
		return $this->value;
	}
}
