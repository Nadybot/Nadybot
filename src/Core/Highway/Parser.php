<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\LoggerWrapper;

use function Safe\json_decode;

class Parser {
	public const SUPPORTED_VERSIONS = ["~0.1.1", "~0.2.0-alpha.1"];

	private const PKG_CLASSES = [
		"hello" => In\Hello::class,
		"error" => In\Error::class,
		"success" => In\Success::class,
		"join" => In\Join::class,
		"room-info" => In\RoomInfo::class,
		"room_info" => In\RoomInfo::class,
		"message" => In\Message::class,
		"leave" => In\Leave::class,
	];

	#[NCA\Logger]
	private static LoggerWrapper $logger;

	public static function parseHighwayPackage(string $data): In\InPackage {
		static::$logger->debug("Parsing {data}", ['data' => $data]);
		$json = json_decode($data, true);
		$mapper = new ObjectMapperUsingReflection();
		$baseInfo = $mapper->hydrateObject(In\InPackage::class, $json);
		$targetClass = self::PKG_CLASSES[$baseInfo->type]??null;
		if (!isset($targetClass)) {
			static::$logger->warning("Unknown Highway package type '{type}'", [
				'type' => $baseInfo->type
			]);
			return $baseInfo;
		}
		if (!class_exists($targetClass)) {
			static::$logger->warning("Implementation for Highway class {class} missing", [
				'class' => $targetClass
			]);
			return $baseInfo;
		}

		try {
			/** @var InPackage */
			$package = $mapper->hydrateObject($targetClass, $json);
		} catch (UnableToHydrateObject $e) {
			throw $e;
		}
		static::$logger->debug("Parsed into {package}", ['package' => $package]);
		return $package;
	}
}
