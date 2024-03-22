<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\CmdContext;
use Nadybot\Modules\WEBSERVER_MODULE\Dataset;

class CmdStats extends Dataset {
	public function getValues(): array {
		$cmdStats = CmdContext::$cmdStats;
		$cmdStats = array_values(
			array_filter($cmdStats, static function (array $stats): bool {
				return time() - $stats[0] <= 600;
			})
		);
		if (empty($cmdStats)) {
			return [];
		}
		usort($cmdStats, static function (array $stats1, array $stats2): int {
			return $stats1[1] <=> $stats2[1];
		});
		$num = count($cmdStats);
		return [
			'# TYPE cmd_stats summary',
			'cmd_stats{quantile="0.01"} ' . (int)ceil($cmdStats[(int)floor($num*0.01)][1]),
			'cmd_stats{quantile="0.05"} ' . (int)ceil($cmdStats[(int)floor($num*0.05)][1]),
			'cmd_stats{quantile="0.5"} ' . (int)ceil($cmdStats[(int)floor($num*0.5)][1]),
			'cmd_stats{quantile="0.9"} ' . (int)ceil($cmdStats[(int)floor($num*0.9)][1]),
			'cmd_stats{quantile="0.99"} ' . (int)ceil($cmdStats[(int)floor($num*0.99)][1]),
			'cmd_stats_sum ' . (int)ceil(array_sum(array_column($cmdStats, 1))),
			'cmd_stats_count ' . count($cmdStats),
		];
	}
}
