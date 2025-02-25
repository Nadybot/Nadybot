<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use InvalidArgumentException;
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\Safe;
use Nadybot\Core\Types\Playfield;
use ValueError;

/**
 * This represents a tower field (site) in the game.
 * The value is always the short playfield name, space, the site ID
 */
class PTowerSite extends Base {
	/** The playfield of this tower site */
	public readonly Playfield $pf;

	/** The site id of this tower site */
	public readonly int $site;
	protected static string $regExp = "[0-9A-Za-z]+[A-Za-z]{1,3}\s*\d+";
	protected readonly string $value;

	public function __construct(string $value) {
		if (!count($matches = Safe::pregMatch("/^([0-9A-Za-z]+[A-Za-z])\s*(\d+)$/", $value))) {
			throw new InvalidArgumentException(__CLASS__ . '() needs a tower site');
		}
		try {
			$this->pf = Playfield::fromName($matches[1]);
		} catch (ValueError $e) {
			throw new UserException(
				message: "<highlight>{$matches[1]}<end> is not a known playfield.",
				previous: $e,
			);
		}
		$this->site = (int)$matches[2];
		$this->value = "{$this->pf->short()} {$this->site}";
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	public static function getExample(): string {
		return '&lt;tower site&gt;';
	}
}
