<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PColor extends Base {
	protected static string $regExp = "(?:<font\s+color\s*=\s*['\"]?)?#?[a-fA-F0-9]{6}(?:['\"]?[^>]*>)?";
	protected string $value;

	/** Just the hex code like AA00FF */
	protected string $code;

	/** The hex code with #, e.g. #AA00FF */
	protected string $hex;

	/** The full html tag: <font color=#AA00FF> */
	protected string $html;

	public function __construct(string $value) {
		preg_match("/([a-fA-F0-9]{6})/", $value, $matches);
		$this->value = $this->code = strtoupper($matches[1]);
		$this->hex = "#{$this->code}";
		$this->html = "<font color={$this->hex}>";
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	/** Just the hex code like AA00FF */
	public function getCode(): string {
		return $this->code;
	}

	/** The hex code with #, e.g. #AA00FF */
	public function getHex(): string {
		return $this->hex;
	}

	/** The full html tag: <font color=#AA00FF> */
	public function getHTML(): string {
		return $this->html;
	}
}
