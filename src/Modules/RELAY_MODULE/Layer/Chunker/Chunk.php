<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer\Chunker;

use Spatie\DataTransferObject\DataTransferObject;

class Chunk extends DataTransferObject {
	public string $id;
	public int $part;
	public int $count;
	public int $sent;
	public string $data;
}
