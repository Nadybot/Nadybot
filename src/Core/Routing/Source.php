<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nadybot\Core\{Config\BotConfig, Registry, Safe};

use Nadybot\Core\DBSchema\RouteHopFormat;

/** This represents a hop where messages pass by, can be created at, or forwarded to */
class Source {
	public const RELAY = 'relay';
	public const ORG = 'aoorg';
	public const PUB = 'aopub';
	public const PRIV = 'aopriv';
	public const TELL = 'aotell';
	public const WEB = 'web';
	public const DISCORD_PRIV = 'discordpriv';
	public const DISCORD_MSG = 'discordmsg';
	public const TRADEBOT = 'tradebot';
	public const IRC = 'irc';
	public const LOG = 'log';
	public const SYSTEM = 'system';
	public const CONSOLE = 'console';

	/** The AO dimension this source belongs to */
	public int $server;

	/** @var Collection<int,RouteHopFormat> */
	public static Collection $format;

	/**
	 * @param string      $type      The type of hop (aoorg, aopriv, web, etc.)
	 * @param string      $name      The full name of the hop (e.g. the name of the
	 *                               org or the discord channel)
	 * @param null|string $label     The label to show instead of the name, or `null` if identical
	 * @param null|int    $dimension The dimension for this hop, or `null` to use the bot's
	 */
	public function __construct(
		public string $type,
		public string $name,
		public ?string $label=null,
		?int $dimension=null
	) {
		if (!isset($dimension)) {
			$config = Registry::getInstance(BotConfig::class);
			$this->server = $config->main->dimension;
		} else {
			$this->server = $dimension;
		}
	}

	/**
	 * Create an instance based on a channel's name
	 *
	 * @param string $channel The full name of the channel in `type(name)` format
	 *
	 * Example:
	 * ```php
	 * Source::fromChannel('aopriv(Nadybot)')
	 * ```
	 */
	public static function fromChannel(string $channel): self {
		if (count($matches = Safe::pregMatch("/^(.+?)\((.+?)\)$/", $channel))) {
			return new self($matches[1], $matches[2]);
		}
		throw new InvalidArgumentException("\$channel ({$channel}) is not a valid channel name.");
	}

	/** Get the format that's defined for this hop */
	public function getFormat(): ?RouteHopFormat {
		$exactMatch = static::$format->first(
			function (RouteHopFormat $format): bool {
				return str_contains($format->hop, '(')
					&& fnmatch($format->hop, "{$this->type}({$this->name})", \FNM_CASEFOLD);
			}
		);
		$exactMatch ??= static::$format->first(
			function (RouteHopFormat $format): bool {
				return fnmatch($format->hop, $this->type, \FNM_CASEFOLD);
			}
		);
		return $exactMatch;
	}

	/**
	 * Render this hop as a string
	 *
	 * @param ?Source $lastHop The hop that was just passed by. This is used to distinguish
	 *                         between rendering the private channel of a bot
	 *                         as the bot's name, or as `Guest`.
	 *
	 * @return ?string `null` if this hop should not be rendered at all
	 */
	public function render(?Source $lastHop): ?string {
		$name = $this->label ?? $this->name;
		if (isset($lastHop) && $this->type === static::PRIV && $lastHop->type === static::ORG) {
			$name = $this->label ?? 'Guest';
		}
		$exactMatch = $this->getFormat();
		if (!isset($exactMatch)) {
			return $name;
		}
		if ($exactMatch->render === false) {
			return null;
		}
		if (str_contains($exactMatch->format, '%s')) {
			return sprintf($exactMatch->format, $name);
		}
		return $exactMatch->format;
	}
}
