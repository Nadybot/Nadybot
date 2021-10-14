<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Registry;

class ScoutInfo extends DBRow {
	public int $playfield_id;
	public int $site_number;
	public int $scouted_on;
	public string $scouted_by;
	public ?int $ql = null;
	public ?string $org_name = null;
	public ?string $faction = null;
	public ?int $close_time = null;
	public ?int $created_at = null;
	public ?int $penalty_duration = null;
	public ?int $penalty_until = null;
	public string $source = "scout";

	public function __construct() {
		$this->scouted_on = time();
	}

	public static function fromApiSite(ApiSite $data): self {
		$scout = new self();
		$vars = get_class_vars(self::class);
		foreach ($vars as $name => $dummy) {
			if (property_exists($data, $name)) {
				$scout->{$name} = $data->{$name};
			}
		}
		$scout->scouted_on = $data->created_at ?? time();
		$scout->scouted_by = Registry::getInstance("chatBot")->char->name;
		return $scout;
	}
}
