<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use Nadybot\Core\JSONDataModel;

class Show extends JSONDataModel {
	public int $live = 0;
	public string $name = "";
	public string $info = "";
	public string $date;
	public int $status;
	public string $deejay = "Auto DJ";
	public int $listeners = 0;

	/** @var \Nadybot\Modules\GSP_MODULE\Song[] */
	public array $history = [];
	/** @var \Nadybot\Modules\GSP_MODULE\Stream[] */
	public array $stream = [];
}
