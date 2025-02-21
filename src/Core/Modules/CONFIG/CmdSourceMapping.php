<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	Attributes\JSON,
	DBSchema\CmdPermSetMapping,
	Safe
};

/** The full configuration for a single command source */
class CmdSourceMapping {
	/**
	 * @param string  $source               The name of this command source
	 * @param string  $permission_set       The permission set to map $source to
	 * @param string  $cmd_prefix           The prefix that triggers a command if it's the first letter
	 * @param ?string $sub_source           The value for the sub-source, or null if none
	 * @param bool    $cmd_prefix_optional  Is the prefix required to interpret the msg as command or optional
	 * @param bool    $unknown_cmd_feedback Shall we report an error if the command doesn't exist
	 */
	public function __construct(
		#[JSON\Ignore] public string $source,
		public string $permission_set,
		public string $cmd_prefix,
		public ?string $sub_source=null,
		public bool $cmd_prefix_optional=false,
		public bool $unknown_cmd_feedback=true,
	) {
	}

	public static function fromPermSetMapping(CmdPermSetMapping $map): self {
		$subSource = null;
		if (count($matches = Safe::pregMatch("/^(.*?)\((.*)\)$/", $map->source)) === 3) {
			$source = $matches[1];
			$subSource = $matches[2];
		} else {
			$source = $map->source;
		}
		return new self(
			source: $source,
			sub_source: $subSource,
			cmd_prefix: $map->symbol,
			cmd_prefix_optional: $map->symbol_optional,
			permission_set: $map->permission_set,
			unknown_cmd_feedback: $map->feedback,
		);
	}

	public function toPermSetMapping(): CmdPermSetMapping {
		$source = $this->source . (isset($this->sub_source) ? "({$this->sub_source})" : '');
		return new CmdPermSetMapping(
			source: $source,
			permission_set: $this->permission_set,
			feedback: $this->unknown_cmd_feedback,
			symbol: $this->cmd_prefix ?? '!',
			symbol_optional: $this->cmd_prefix_optional,
		);
	}
}
