<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\ModuleInstanceInterface;

/** An abstract class for all instances of a module */
abstract class ModuleInstance implements ModuleInstanceInterface {
	/** Set when registering the module */
	protected string $moduleName = '';

	/** {@inheritDoc} */
	public function getModuleName(): string {
		return $this->moduleName;
	}

	/** {@inheritDoc} */
	public function setModuleName(string $name): void {
		$this->moduleName = $name;
	}
}
