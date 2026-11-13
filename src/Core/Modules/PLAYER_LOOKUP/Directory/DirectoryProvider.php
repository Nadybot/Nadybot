<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

/**
 * A source for character and organization data, like PORK, BORK or the
 * directory API of a private server.
 *
 * Modules can provide their own providers by registering an instance
 * that implements this interface and one or more of the feature interfaces.
 */
interface DirectoryProvider {
	/**
	 * A stable, unique id of this provider. Used as value in settings,
	 * so it must never change once released.
	 */
	public function getId(): string;

	/** A human-readable name of this provider, used as label in settings */
	public function getName(): string;

	/** Can this provider deliver data for the given dimension? */
	public function supportsDimension(int $dimension): bool;

	/**
	 * Which features this provider offers
	 *
	 * @return list<DirectoryFeature>
	 */
	public function getFeatures(): array;
}
