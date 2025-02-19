<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This is the interface that defines that a class instance belongs to a module */
interface ModuleInstanceInterface {
	public function setModuleName(string $name): void;

	public function getModuleName(): string;
}
