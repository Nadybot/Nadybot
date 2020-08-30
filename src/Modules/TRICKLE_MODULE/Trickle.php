<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\DBRow;

class Trickle extends DBRow {
	public int $id;
	public int $skill_id;
	public string $groupName;
	public string $name;
	public float $amountAgi;
	public float $amountInt;
	public float $amountPsy;
	public float $amountSta;
	public float $amountStr;
	public float $amountSen;
	public ?int $amount;
}
