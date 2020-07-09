<?php

namespace Budabot\Core;

class CacheResult {
	/**
	 * Is the cache valid?
	 *
	 * @var boolean $success
	 */
	public $success = false;

	/**
	 * Did this data come from the cache (true) or was it  fetched (false)?
	 *
	 * @var boolean $usedCache
	 */
	public $usedCache = false;

	/**
	 * Is this cached infromation outdated?
	 *
	 * Usually, this should not be true, but if the cache
	 * is outdated and we were unable to renew the information
	 * from the URL, because of timeout or invalid content,
	 * then we consider outdated data to be better than none.
	 *
	 * @var boolean $oldCache
	 */
	public $oldCache = false;

	/**
	 * The age of the information in the cache in seconds
	 *
	 * 0 if just refreshed
	 *
	 * @var integer $cacheAge
	 */
	public $cacheAge = 0;

	/**
	 * The cached data as retrieved from the URL's body
	 *
	 * @var string $data
	 */
	public $data;
}
