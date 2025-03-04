<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Attributes\Hydrator\ForceList;
use Nadybot\Core\Safe;

/** This is the test case for a single command and its output */
class TestCase {
	/**
	 * @param string       $command    The command to execute (without !)
	 * @param list<string> $expect     The regular expression(s) to expect in the output
	 * @param null|string  $name       An optional name of the test
	 * @param null|string  $condition  A condition that must evaluate to `true`
	 *                                 for this collection to be processed
	 * @param list<string> $unexpected The regular expression(s) not to expect in the output
	 */
	public function __construct(
		public readonly string $command,
		#[ForceList, CastListToType('string')] public readonly array $expect,
		public readonly ?string $name=null,
		public readonly ?string $condition=null,
		#[ForceList, CastListToType('string')] public readonly array $unexpected=[],
	) {
	}

	public function getName(): string {
		if (isset($this->name)) {
			return $this->name;
		}
		return '!' . Safe::pregReplace('/^!/', '', $this->command);
	}
}
