<?php declare(strict_types=1);

namespace Nadybot\Core;

class CacheResult {
	/**
	 * Is the cache valid?
	 */
	public bool $success = false;

	/**
	 * Did this data come from the cache (true) or was it fetched (false)?
	 */
	public bool $usedCache = false;

	/**
	 * Is this cached information outdated?
	 *
	 * Usually, this should not be true, but if the cache
	 * is outdated and we were unable to renew the information
	 * from the URL, because of timeout or invalid content,
	 * then we consider outdated data to be better than none.
	 */
	public bool $oldCache = false;

	/**
	 * The age of the information in the cache in seconds
	 *
	 * 0 if just refreshed
	 */
	public int $cacheAge = 0;

	/**
	 * The cached data as retrieved from the URL's body
	 */
	public ?string $data = null;
}
