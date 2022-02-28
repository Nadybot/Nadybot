<?php declare(strict_types=1);

namespace Nadybot\Core;

interface ModuleInstanceInterface {
	public function setModuleName(string $name): void;
	public function getModuleName(): string;
}
