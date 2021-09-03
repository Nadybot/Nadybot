<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Spatie\DataTransferObject\DataTransferObject;

class ApiSite extends DataTransferObject {
	public int $playfield_id;
	public string $playfield_long_name;
	public string $playfield_short_name;
	public int $site_number;
	public ?int $ql = null;
	public int $min_ql;
	public int $max_ql;
	public int $x_coord;
	public int $y_coord;
	public ?string $org_name = null;
	public ?int $org_id = null;
	public ?string $faction = null;
	public string $site_name;
	public ?int $close_time = null;
	public ?int $created_at = null;
	public int $enabled;
}
