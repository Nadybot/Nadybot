<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class SystemInformation {
	/** Basic information like OS */
	public BasicSystemInformation $basic;

	/** Memory statistics */
	public MemoryInformation $memory;

	/** Information not fitting any other category */
	public MiscSystemInformation $misc;

	/** Statistics about some configurations */
	public ConfigStatistics $config;

	/** General bot statistics */
	public SystemStats $stats;

	/**
	 * Which channels is the bot listening to
	 * @var ChannelInfo[]
	 */
	public array $channels = [];
}
