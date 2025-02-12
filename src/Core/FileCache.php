<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\File\filesystem;
use function Safe\{pack, unpack};
use Amp\Cache\CacheException;
use Amp\File\{Filesystem, FilesystemException};
use Amp\Sync\{KeyedMutex, Lock};
use Amp\{ForbidCloning, ForbidSerialization};
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

	public function __construct(
		string $directory,
		private readonly KeyedMutex $mutex,
		?Filesystem $filesystem=null,
	) {
		$filesystem ??= filesystem();
		$this->filesystem = $filesystem;
		$this->directory = $directory = \rtrim($directory, '/\\');

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

	public function __destruct() {
		if ($this->gcWatcher !== null) {
			EventLoop::cancel($this->gcWatcher);
		}
	}

	public function clear(): bool {
		foreach ($this->filesystem->listFiles($this->directory) as $file) {
			if (Safe::pregMatch('/^[a-f0-9]{64}\.cache$/', $file)) {
				$this->filesystem->deleteFile($this->directory . '/' . $file);
			}
		}
		return true;
	}

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

			return unserialize($value);
		} catch (\Throwable) {
			return null;
		} finally {
			$lock->release();
		}
	}

	/** @return Generator<string, mixed> */
	public function getMultiple(iterable $keys, mixed $default=null): Generator {
		foreach ($keys as $key) {
			yield $key => $this->get($key, $default);
		}
	}

	/** @param iterable<mixed> $values */
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

	/** @param iterable<string> $keys */
	public function deleteMultiple(iterable $keys): bool {
		$result = true;
		foreach ($keys as $key) {
			$result = $this->delete($key) && $result;
		}
		return $result;
	}

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

	public function has(string $key): bool {
		return $this->filesystem->exists($this->directory . '/' . self::getFilename($key));
	}

	private static function getFilename(string $key): string {
		return \hash('sha256', $key) . '.cache';
	}

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
