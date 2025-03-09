<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{Registry, Safe};

/** A static class to create capturers */
class CapturerFactory {
	/**
	 * Get a capturer for a given route source, like `'aoorg'`, or `'aopriv'`
	 *
	 * @throws UnhandledPatternException
	 */
	public static function fromPattern(string $pattern): CapturerInterface {
		if ($pattern === Source::PRIV) {
			$config = Registry::getInstance(BotConfig::class);
			return new PrivateChannelCapturer($config->main->character);
		}
		if ($pattern === Source::ORG) {
			return new OrgChannelCapturer();
		}
		if (count($target = Safe::pregMatch(chr(1) . Source::TELL . '\((?<target>.+)\)' . chr(1), $pattern)) > 1) {
			$target = Utils::normalizeCharacter($target['target']);
			return new TellCapturer($target);
		}
		if (count($target = Safe::pregMatch(chr(1) . 'tradebot\((?<target>.+)\)' . chr(1), $pattern)) > 1) {
			$target = Utils::normalizeCharacter($target['target']);
			return new TradebotCapturer($target);
		}
		throw new UnhandledPatternException("Unknown pattern \"{$pattern}\"");
	}
}
