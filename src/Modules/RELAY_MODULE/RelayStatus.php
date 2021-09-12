<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

class RelayStatus {
	public const ERROR = "error";
	public const INIT = "warning";
	public const READY = "ready";

	public string $type = self::ERROR;

	public string $text = "Unknown";

	public function __construct(string $type=self::ERROR, string $text="Unknown") {
		$this->type = $type;
		$this->text = $text;
	}

	public function toString(): string {
		$statusMap = [
			static::ERROR => "red",
			static::INIT => "yellow",
			static::READY => "green",
		];

		$color = $statusMap[$this->type] ?? "red";
		return "<{$color}>{$this->text}<end>";
	}
}
