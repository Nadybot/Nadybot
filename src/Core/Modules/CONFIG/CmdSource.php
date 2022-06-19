<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

class CmdSource {
	/**
	 * The name/mask of this command source
	 * If the name contains an asterisk wildcard, it
	 * represents a mask of possible sources, e.g. aotell(*)
	 */
	public string $source;

	public bool $has_sub_sources = false;

	/**
	 * A list of permission set mappings this command source maps to
	 *
	 * @var CmdSourceMapping[]
	 */
	public array $mappings = [];

	public static function fromMask(string $mask): self {
		$res = new self();
		$res->source = $mask;
		if (substr($res->source, -3) === "(*)") {
			$res->source = substr($res->source, 0, -3);
			$res->has_sub_sources = true;
		}
		return $res;
	}
}
