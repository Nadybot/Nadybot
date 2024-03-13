<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\File\{File, Filesystem as AmpFilesystem, FilesystemException};
use Psr\Log\LoggerInterface;

final class Filesystem {
	/** Internal counter to track the nth function call reliably */
	private static int $callNum = 1;

	public function __construct(
		private AmpFilesystem $fs,
		private ?LoggerInterface $logger=null,
	) {
	}

	public function getFilesystem(): AmpFilesystem {
		return $this->fs;
	}

	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}

	/**
	 * realPath expands all symbolic links and
	 * resolves references to /./, /../ and extra / characters in
	 * the input path and returns the canonicalized
	 * absolute pathname.
	 *
	 * @param string $path The path being checked.
	 *                     In this case, the value is interpreted as the current directory.
	 *
	 * @return string Returns the canonicalized absolute pathname on success. The resulting path
	 *                will have no symbolic link, /./ or /../ components. Trailing delimiters,
	 *                such as \ and /, are also removed.
	 *
	 * realPath throws a FilesystemException on failure, e.g. if
	 * the file does not exist.
	 *
	 * @throws FilesystemException
	 */
	public function realPath(string $path): string {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> realPath({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		try {
			return \Safe\realpath($path);
		} catch (\Exception $e) {
			throw new FilesystemException($e->getMessage(), $e);
		} finally {
			$this->logger?->debug("[{call}] <- realPath({path})", [
				"call" => sprintf("%6d", $callNum),
				"path" => $path,
			]);
		}
	}

	/**
	 * Open a handle for the specified path.
	 *
	 * @throws FilesystemException
	 */
	public function openFile(string $path, string $mode): File {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> openFile({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => $mode,
		]);
		$result = $this->fs->openFile($path, $mode);
		$this->logger?->debug("[{call}] <- openFile({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => $mode,
		]);
		return $result;
	}

	/**
	 * Execute a file stat operation.
	 *
	 * If the requested path does not exist, it will return NULL.
	 *
	 * @param string $path File system path.
	 *
	 * @return null|array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int,8:int,9:int,10:int,11:int,12:int,"dev":int,"ino":int,"mode":int,"nlink":int,"uid":int,"gid":int,"rdev":int,"size":int,"atime":int,"mtime":int,"ctime":int,"blksize":int,"blocks":int}
	 */
	public function getStatus(string $path): ?array {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> getStatus({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->getStatus($path);
		$this->logger?->debug("[{call}] <- getStatus({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result; // @phpstan-ignore-line
	}

	/**
	 * Same as {@see Filesystem::getStatus()} except if the path is a link then the link's data is returned.
	 *
	 * If the requested path does not exist, it will return NULL.
	 *
	 * @param string $path File system path.
	 *
	 * @return null|array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int,8:int,9:int,10:int,11:int,12:int,"dev":int,"ino":int,"mode":int,"nlink":int,"uid":int,"gid":int,"rdev":int,"size":int,"atime":int,"mtime":int,"ctime":int,"blksize":int,"blocks":int}
	 */
	public function getLinkStatus(string $path): ?array {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> getLinkStatus({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->getLinkStatus($path);
		$this->logger?->debug("[{call}] <- getLinkStatus({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result; // @phpstan-ignore-line
	}

	/**
	 * Does the specified path exist?
	 *
	 * This function should never resolve as a failure -- only a successful bool value
	 * indicating the existence of the specified path.
	 *
	 * @param string $path File system path.
	 */
	public function exists(string $path): bool {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> exists({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->exists($path);
		$this->logger?->debug("[{call}] <- exists({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Retrieve the size in bytes of the file at the specified path.
	 *
	 * If the path does not exist or is not a regular file, this method will throw.
	 *
	 * @param string $path File system path.
	 *
	 * @return int Size in bytes.
	 *
	 * @throws FilesystemException If the path does not exist or is not a file.
	 */
	public function getSize(string $path): int {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> getSize({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->getSize($path);
		$this->logger?->debug("[{call}] <- getSize({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Does the specified path exist and is it a directory?
	 *
	 * @param string $path File system path.
	 */
	public function isDirectory(string $path): bool {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> isDirectory({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->isDirectory($path);
		$this->logger?->debug("[{call}] <- isDirectory({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Does the specified path exist and is it a file?
	 *
	 * @param string $path File system path.
	 */
	public function isFile(string $path): bool {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> isFile({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->isFile($path);
		$this->logger?->debug("[{call}] <- isFile({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Does the specified path exist and is it a symlink?
	 *
	 * If the path does not exist, this method will return FALSE.
	 *
	 * @param string $path File system path.
	 */
	public function isSymlink(string $path): bool {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> isSymlink({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->isSymlink($path);
		$this->logger?->debug("[{call}] <- isSymlink({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Retrieve the path's last modification time as a unix timestamp.
	 *
	 * @param string $path File system path.
	 *
	 * @throws FilesystemException If the path does not exist.
	 */
	public function getModificationTime(string $path): int {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> getModificationTime({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->getModificationTime($path);
		$this->logger?->debug("[{call}] <- getModificationTime({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Retrieve the path's last access time as a unix timestamp.
	 *
	 * @param string $path File system path.
	 *
	 * @throws FilesystemException If the path does not exist.
	 */
	public function getAccessTime(string $path): int {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> getAccessTime({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->getAccessTime($path);
		$this->logger?->debug("[{call}] <- getAccessTime({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Retrieve the path's creation time as a unix timestamp.
	 *
	 * @param string $path File system path.
	 *
	 * @throws FilesystemException If the path does not exist.
	 */
	public function getCreationTime(string $path): int {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> getCreationTime({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->getCreationTime($path);
		$this->logger?->debug("[{call}] <- getCreationTime({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Create a symlink $link pointing to the file/directory located at $original.
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function createSymlink(string $original, string $link): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> createSymlink({original}, {link})", [
			"call" => sprintf("%6d", $callNum),
			"original" => $original,
			"link" => $link,
		]);
		$this->fs->createSymlink($original, $link);
		$this->logger?->debug("[{call}] <- createSymlink({original}, {link})", [
			"call" => sprintf("%6d", $callNum),
			"original" => $original,
			"link" => $link,
		]);
	}

	/** Create a hard link $link pointing to the file/directory located at $target. */
	public function createHardlink(string $target, string $link): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> createHardlink({target}, {link})", [
			"call" => sprintf("%6d", $callNum),
			"original" => $target,
			"link" => $link,
		]);
		$this->fs->createHardlink($target, $link);
		$this->logger?->debug("[{call}] <- createHardlink({target}, {link})", [
			"call" => sprintf("%6d", $callNum),
			"original" => $target,
			"link" => $link,
		]);
	}

	/**
	 * Resolve the symlink at $path.
	 *
	 * @throws FilesystemException
	 */
	public function resolveSymlink(string $path): string {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> resolveSymlink({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->resolveSymlink($path);
		$this->logger?->debug("[{call}] <- resolveSymlink({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Move / rename a file or directory.
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function move(string $from, string $to): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> move({from}, {to})", [
			"call" => sprintf("%6d", $callNum),
			"from" => $from,
			"to" => $to,
		]);
		$this->fs->move($from, $to);
		$this->logger?->debug("[{call}] <- move({from}, {to})", [
			"call" => sprintf("%6d", $callNum),
			"from" => $from,
			"to" => $to,
		]);
	}

	/**
	 * Delete a file.
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function deleteFile(string $path): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> deleteFile({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$this->fs->deleteFile($path);
		$this->logger?->debug("[{call}] <- deleteFile({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
	}

	/**
	 * Create a directory.
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function createDirectory(string $path, int $mode=0777): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> createDirectory({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => sprintf("0%o", $mode),
		]);
		$this->fs->createDirectory($path, $mode);
		$this->logger?->debug("[{call}] <- createDirectory({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => sprintf("0%o", $mode),
		]);
	}

	/**
	 * Create a directory recursively.
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function createDirectoryRecursively(string $path, int $mode=0777): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> createDirectoryRecursively({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => sprintf("0%o", $mode),
		]);
		$this->fs->createDirectoryRecursively($path, $mode);
		$this->logger?->debug("[{call}] <- createDirectoryRecursively({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => sprintf("0%o", $mode),
		]);
	}

	/**
	 * Delete a directory.
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function deleteDirectory(string $path): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> deleteDirectory({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$this->fs->deleteDirectory($path);
		$this->logger?->debug("[{call}] <- deleteDirectory({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
	}

	/**
	 * Retrieve an array of files and directories inside the specified path.
	 *
	 * Dot entries are not included in the resulting array (i.e. "." and "..").
	 *
	 * @return list<string>
	 *
	 * @throws FilesystemException If the operation fails.
	 */
	public function listFiles(string $path): array {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> listFiles({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->listFiles($path);
		$this->logger?->debug("[{call}] <- listFiles({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/** Change permissions of a file or directory. */
	public function changePermissions(string $path, int $mode): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> changePermissions({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => sprintf("0%o", $mode),
		]);
		$this->fs->changePermissions($path, $mode);
		$this->logger?->debug("[{call}] <- changePermissions({path}, {mode})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mode" => sprintf("0%o", $mode),
		]);
	}

	/**
	 * Change ownership of a file or directory.
	 *
	 * @param int|null $uid null to ignore
	 * @param int|null $gid null to ignore
	 */
	public function changeOwner(string $path, ?int $uid, ?int $gid=null): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> changeOwner({path}, {uid}, {gid})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"uid" => $uid ?? 'null',
			"gid" => $gid ?? 'null',
		]);
		$this->fs->changeOwner($path, $uid, $gid);
		$this->logger?->debug("[{call}] <- changeOwner({path}, {uid}, {gid})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"uid" => $uid ?? 'null',
			"gid" => $gid ?? 'null',
		]);
	}

	/**
	 * Update the access and modification time of the specified path.
	 *
	 * If the file does not exist it will be created automatically.
	 *
	 * @param int|null $modificationTime The touch time. If $time is not supplied, the current system time is used.
	 * @param int|null $accessTime       The access time. If not supplied, the modification time is used.
	 */
	public function touch(string $path, ?int $modificationTime=null, ?int $accessTime=null): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> touch({path}, {mtime}, {atime})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mtime" => $modificationTime ?? 'null',
			"atime" => $accessTime ?? 'null',
		]);
		$this->fs->touch($path, $modificationTime, $accessTime);
		$this->logger?->debug("[{call}] <- touch({path}, {mtime}, {atime})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"mtime" => $modificationTime ?? 'null',
			"atime" => $accessTime ?? 'null',
		]);
	}

	/**
	 * Buffer the specified file's contents.
	 *
	 * @param string $path The file path from which to buffer contents.
	 */
	public function read(string $path): string {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> read({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$result = $this->fs->read($path);
		$this->logger?->debug("[{call}] <- read({path})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		return $result;
	}

	/**
	 * Write the contents string to the specified path.
	 *
	 * @param string $path     The file path to which to $contents should be written.
	 * @param string $contents The data to write to the specified $path.
	 */
	public function write(string $path, string $contents): void {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> write({path} ,…)", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
		$this->fs->write($path, $contents);
		$this->logger?->debug("[{call}] <- write({path} ,…)", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
		]);
	}

	/**
	 * Creates a file with a unique filename, with access permission set to 0600, in the specified directory.
	 * If the directory does not exist or is not writable, tempnam may
	 * generate a file in the system's temporary directory, and return
	 * the full path to that file, including its name.
	 *
	 * @param string $path   The path where there file is to be created in.
	 * @param string $prefix The prefix of the generated temporary filename.
	 *
	 * @return string Returns the new temporary filename (with path).
	 *
	 * @throws FilesystemException
	 */
	public function tempnam(string $path, string $prefix): string {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> tempnam({path}, {prefix})", [
			"call" => sprintf("%6d", $callNum),
			"path" => $path,
			"prefix" => $prefix,
		]);
		try {
			return \Safe\tempnam($path, $prefix);
		} catch (\Safe\Exceptions\FilesystemException $e) {
			throw new FilesystemException($e->getMessage(), $e);
		} finally {
			$this->logger?->debug("[{call}] <- tempnam({path}, {prefix})", [
				"call" => sprintf("%6d", $callNum),
				"path" => $path,
				"prefix" => $prefix,
			]);
		}
	}

	/**
	 * Creates a temporary file with a unique name in read-write (w+) mode and
	 * returns a file handle.
	 *
	 * The file is automatically removed when closed (for example, by calling
	 * fclose, or when there are no remaining references to
	 * the file handle returned by tmpfile), or when the
	 * script ends.
	 *
	 * @return resource Returns a file handle, similar to the one returned by
	 *                  fopen, for the new file.
	 *
	 * @throws FilesystemException
	 */
	public function tmpfile() {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> tmpfile()", [
			"call" => sprintf("%6d", $callNum),
		]);
		try {
			return \Safe\tmpfile();
		} catch (\Safe\Exceptions\FilesystemException $e) {
			throw new FilesystemException($e->getMessage(), $e);
		} finally {
			$this->logger?->debug("[{call}] <- tmpfile()", [
				"call" => sprintf("%6d", $callNum),
			]);
		}
	}

	/**
	 * Reads an entire file into an array.
	 *
	 * @param string $filename Path to the file.
	 *
	 * @return string[] Returns the file in an array. Each element of the array corresponds to a
	 *                  line in the file, without the newline. Upon failure,
	 *                  file throws a FilesystemException
	 *
	 * @throws FilesystemException
	 */
	public function file(string $filename): array {
		$callNum = self::$callNum++;
		$this->logger?->debug("[{call}] -> file({filename})", [
			"call" => sprintf("%6d", $callNum),
			"filename" => $filename,
		]);
		try {
			return \Safe\preg_split("/\r\n|\n|\r/", $this->read($filename));
		} catch (\Safe\Exceptions\FilesystemException $e) {
			throw new FilesystemException($e->getMessage(), $e);
		} finally {
			$this->logger?->debug("[{call}] <- file({filename})", [
				"call" => sprintf("%6d", $callNum),
				"filename" => $filename,
			]);
		}
	}
}
