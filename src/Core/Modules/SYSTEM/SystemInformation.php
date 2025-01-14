<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

/** A collection of several statistics and config options of the bot */
class SystemInformation {
	/**
	 * @param BasicSystemInformation $basic    Basic information like OS
	 * @param MemoryInformation      $memory   Memory statistics
	 * @param MiscSystemInformation  $misc     Information not fitting any other category
	 * @param ConfigStatistics       $config   Statistics about some configurations
	 * @param SystemStats            $stats    General bot statistics
	 * @param ChannelInfo[]          $channels Which channels is the bot listening to
	 *
	 * @psalm-param list<ChannelInfo> $channels
	 */
	public function __construct(
		public BasicSystemInformation $basic,
		public MemoryInformation $memory,
		public MiscSystemInformation $misc,
		public ConfigStatistics $config,
		public SystemStats $stats,
		public array $channels=[],
	) {
	}
}
