<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\File\filesystem;
use function Safe\{pack, unpack};
use Amp\Cache\CacheException;
use Amp\File\{Filesystem, FilesystemException};
use Amp\{ForbidCloning, ForbidSerialization};
use Amp\Sync\{KeyedMutex, Lock};
use Generator;
use Nadybot\Core\Exceptions\InvalidCacheKeyException;
use Psr\SimpleCache\CacheInterface;

use Revolt\EventLoop;
use Safe\DateTimeImmutable;

/**
 * A cache which stores data in files in a directory.
 */
final class FileCache implements CacheInterface {
	use ForbidCloning;
	use ForbidSerialization;

	private readonly Filesystem $filesystem;

	private readonly string $directory;

	private ?string $gcWatcher = null;

	/**
	 * @param string          $directory  The directory where to keep the cached data
	 * @param KeyedMutex      $mutex      The mutex to use for locking
	 * @param null|Filesystem $filesystem The file system instance to use
	 */
	public function __construct(
		string $directory,
		private readonly KeyedMutex $mutex,
		?Filesystem $filesystem=null,
	) {
		$filesystem ??= filesystem();
		$this->filesystem = $filesystem;
		$this->directory = $directory = \rtrim($directory, '/\\');
		if (!$filesystem->exists($this->directory)) {
			$filesystem->createDirectory($this->directory, 0o700);
		}

		$gcWatcher = static function () use ($directory, $mutex, $filesystem): void {
			try {
				$files = $filesystem->listFiles($directory);

				foreach ($files as $file) {
					if (\strlen($file) !== 70 || !\str_ends_with($file, '.cache')) {
						continue;
					}

					try {
						$lock = $mutex->acquire($file);
					} catch (\Throwable) {
						continue;
					}

					try {
						$handle = $filesystem->openFile($directory . '/' . $file, 'r');
						$ttl = $handle->read(length: 4);

						if ($ttl === null || \strlen($ttl) !== 4) {
							$handle->close();
							continue;
						}

						$ttl = unpack('Nttl', $ttl)['ttl'];
						if ($ttl < \time()) {
							$filesystem->deleteFile($directory . '/' . $file);
						}
					} catch (\Throwable) {
						// ignore
					} finally {
						$lock->release();
					}
				}
			} catch (\Throwable) {
				// ignore
			}
		};

		// trigger once, so short running scripts also GC and don't grow forever
		EventLoop::defer($gcWatcher);

		$this->gcWatcher = EventLoop::repeat(300, $gcWatcher);

		EventLoop::unreference($this->gcWatcher);
	}

	/** Turn of the garbage collection */
	public function __destruct() {
		if ($this->gcWatcher !== null) {
			EventLoop::cancel($this->gcWatcher);
		}
	}

	/** {@inheritDoc} */
	public function clear(): bool {
		foreach ($this->filesystem->listFiles($this->directory) as $file) {
			if (Safe::pregMatch('/^[a-f0-9]{64}\.cache$/', $file)) {
				$this->filesystem->deleteFile($this->directory . '/' . $file);
			}
		}
		return true;
	}

	/** {@inheritDoc} */
	public function get(string $key, mixed $default=null): mixed {
		$filename = self::getFilename($key);
		if (!$this->has($key)) {
			return $default;
		}

		$lock = $this->lock($filename);

		try {
			$cacheContent = $this->filesystem->read($this->directory . '/' . $filename);

			if (strlen($cacheContent) < 4) {
				return null;
			}

			$ttl = unpack('Nttl', substr($cacheContent, 0, 4))['ttl'];
			if ($ttl < time()) {
				$this->filesystem->deleteFile($this->directory . '/' . $filename);

				return null;
			}

			$value = substr($cacheContent, 4);

			return unserialize($value, ['allowed_classes' => [\stdClass::class]]);
		} catch (\Throwable) {
			return null;
		} finally {
			$lock->release();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param iterable<mixed, string> $keys A list of keys that can be obtained in a single operation.
	 *
	 * @return Generator<string, mixed>
	 */
	public function getMultiple(iterable $keys, mixed $default=null): Generator {
		foreach ($keys as $key) {
			yield $key => $this->get($key, $default);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param iterable<mixed> $values
	 */
	public function setMultiple(iterable $values, null|int|\DateInterval $ttl=null): bool {
		$result = true;
		foreach ($values as $key => $value) {
			if (!is_string($key)) {
				throw new InvalidCacheKeyException("Invalid cache key \"{$key}\" given.");
			}
			$result = $this->set($key, $value, $ttl) && $result;
		}
		return $result;
	}

	/** {@inheritDoc} */
	public function set(string $key, mixed $value, null|int|\DateInterval $ttl=null): bool {
		if (is_int($ttl)) {
			$ttl = time() + $ttl;
		} elseif ($ttl instanceof \DateInterval) {
			$ttl = (new DateTimeImmutable('now'))->add($ttl)->getTimestamp();
		}
		$ttl ??= \PHP_INT_MAX;

		if ($ttl < 0) {
			throw new \Error("Invalid cache TTL ({$ttl}); integer >= 0 or null required");
		}

		$filename = self::getFilename($key);
		$lock = $this->lock($filename);

		$encodedTtl = pack('N', $ttl);
		$encodedValue = serialize($value);

		try {
			$this->filesystem->write($this->directory . '/' . $filename, $encodedTtl . $encodedValue);
		} catch (\Throwable) {
			return false;
		} finally {
			$lock->release();
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param iterable<string> $keys
	 */
	public function deleteMultiple(iterable $keys): bool {
		$result = true;
		foreach ($keys as $key) {
			$result = $this->delete($key) && $result;
		}
		return $result;
	}

	/** {@inheritDoc} */
	public function delete(string $key): bool {
		$filename = self::getFilename($key);

		$lock = $this->lock($filename);

		try {
			$this->filesystem->deleteFile($this->directory . '/' . $filename);
		} catch (FilesystemException) {
			return false;
		} finally {
			$lock->release();
		}

		return true;
	}

	/** {@inheritDoc} */
	public function has(string $key): bool {
		return $this->filesystem->exists($this->directory . '/' . self::getFilename($key));
	}

	/** Get the file name to store the cache data for a given key */
	private static function getFilename(string $key): string {
		return \hash('sha256', $key) . '.cache';
	}

	/** Acquire a lock for the given cache key */
	private function lock(string $key): Lock {
		try {
			return $this->mutex->acquire($key);
		} catch (\Throwable $exception) {
			throw new CacheException(
				\sprintf('Exception thrown when obtaining the lock for key "%s"', $key),
				0,
				$exception
			);
		}
	}
}
