<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer\Chunker;

class Chunk {
	public function __construct(
		public string $id,
		public int $part,
		public int $count,
		public int $sent,
		public string $data,
	) {
	}
}
