<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This is the interface that defines that a class instance belongs to a module */
interface ModuleInstanceInterface {
	/** Set the module name for this instance */
	public function setModuleName(string $name): void;

	/** Get the module name of this instance */
	public function getModuleName(): string;
}
