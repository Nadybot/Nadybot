<?php declare(strict_types=1);

namespace Nadybot\Core;

class ModuleInstance implements ModuleInstanceInterface {
	/** Set when registering the module */
	public string $moduleName = "";

	public function getModuleName(): string {
		return $this->moduleName;
	}

	public function setModuleName(string $name): void {
		$this->moduleName = $name;
	}
}
