<?php
class ZipArchive implements Countable {
	/** @var int */ public const CREATE=0;
	/** @var int */ public const EXCL=0;
	/** @var int */ public const CHECKCONS=0;
	/** @var int */ public const OVERWRITE=0;
	/** @var int */ public const RDONLY=0;
	/** @var int */ public const FL_NOCASE=0;
	/** @var int */ public const FL_NODIR=0;
	/** @var int */ public const FL_COMPRESSED=0;
	/** @var int */ public const FL_UNCHANGED=0;
	/** @var int */ public const FL_RECOMPRESS=0;
	/** @var int */ public const FL_ENCRYPTED=0;
	/** @var int */ public const FL_OVERWRITE=0;
	/** @var int */ public const FL_LOCAL=0;
	/** @var int */ public const FL_CENTRAL=0;
	/** @var int */ public const FL_ENC_GUESS=0;
	/** @var int */ public const FL_ENC_RAW=0;
	/** @var int */ public const FL_ENC_STRICT=0;
	/** @var int */ public const FL_ENC_UTF_8=0;
	/** @var int */ public const FL_ENC_CP437=0;
	/** @var int */ public const FL_OPEN_FILE_NOW=0;
	/** @var int */ public const CM_DEFAULT=0;
	/** @var int */ public const CM_STORE=0;
	/** @var int */ public const CM_SHRINK=0;
	/** @var int */ public const CM_REDUCE_1=0;
	/** @var int */ public const CM_REDUCE_2=0;
	/** @var int */ public const CM_REDUCE_3=0;
	/** @var int */ public const CM_REDUCE_4=0;
	/** @var int */ public const CM_IMPLODE=0;
	/** @var int */ public const CM_DEFLATE=0;
	/** @var int */ public const CM_DEFLATE64=0;
	/** @var int */ public const CM_PKWARE_IMPLODE=0;
	/** @var int */ public const CM_BZIP2=0;
	/** @var int */ public const CM_LZMA=0;
	/** @var int */ public const CM_LZMA2=0;
	/** @var int */ public const CM_ZSTD=0;
	/** @var int */ public const CM_XZ=0;
	/** @var int */ public const CM_TERSE=0;
	/** @var int */ public const CM_LZ77=0;
	/** @var int */ public const CM_WAVPACK=0;
	/** @var int */ public const CM_PPMD=0;
	/** @var int */ public const ER_OK=0;
	/** @var int */ public const ER_MULTIDISK=0;
	/** @var int */ public const ER_RENAME=0;
	/** @var int */ public const ER_CLOSE=0;
	/** @var int */ public const ER_SEEK=0;
	/** @var int */ public const ER_READ=0;
	/** @var int */ public const ER_WRITE=0;
	/** @var int */ public const ER_CRC=0;
	/** @var int */ public const ER_ZIPCLOSED=0;
	/** @var int */ public const ER_NOENT=0;
	/** @var int */ public const ER_EXISTS=0;
	/** @var int */ public const ER_OPEN=0;
	/** @var int */ public const ER_TMPOPEN=0;
	/** @var int */ public const ER_ZLIB=0;
	/** @var int */ public const ER_MEMORY=0;
	/** @var int */ public const ER_CHANGED=0;
	/** @var int */ public const ER_COMPNOTSUPP=0;
	/** @var int */ public const ER_EOF=0;
	/** @var int */ public const ER_INVAL=0;
	/** @var int */ public const ER_NOZIP=0;
	/** @var int */ public const ER_INTERNAL=0;
	/** @var int */ public const ER_INCONS=0;
	/** @var int */ public const ER_REMOVE=0;
	/** @var int */ public const ER_DELETED=0;
	/** @var int */ public const ER_ENCRNOTSUPP=0;
	/** @var int */ public const ER_RDONLY=0;
	/** @var int */ public const ER_NOPASSWD=0;
	/** @var int */ public const ER_WRONGPASSWD=0;
	/** @var int */ public const ER_OPNOTSUPP=0;
	/** @var int */ public const ER_INUSE=0;
	/** @var int */ public const ER_TELL=0;
	/** @var int */ public const ER_COMPRESSED_DATA=0;
	/** @var int */ public const ER_CANCELLED=0;
	/** @var int */ public const ER_DATA_LENGTH=0;
	/** @var int */ public const ER_NOT_ALLOWED=0;
	/** @var int */ public const ER_TRUNCATED_ZIP=0;
	/** @var int */ public const AFL_RDONLY=0;
	/** @var int */ public const AFL_IS_TORRENTZIP=0;
	/** @var int */ public const AFL_WANT_TORRENTZIP=0;
	/** @var int */ public const AFL_CREATE_OR_KEEP_FILE_FOR_EMPTY_ARCHIVE=0;
	/** @var int */ public const OPSYS_DOS=0;
	/** @var int */ public const OPSYS_AMIGA=0;
	/** @var int */ public const OPSYS_OPENVMS=0;
	/** @var int */ public const OPSYS_UNIX=0;
	/** @var int */ public const OPSYS_VM_CMS=0;
	/** @var int */ public const OPSYS_ATARI_ST=0;
	/** @var int */ public const OPSYS_OS_2=0;
	/** @var int */ public const OPSYS_MACINTOSH=0;
	/** @var int */ public const OPSYS_Z_SYSTEM=0;
	/** @var int */ public const OPSYS_CPM=0;
	/** @var int */ public const OPSYS_WINDOWS_NTFS=0;
	/** @var int */ public const OPSYS_MVS=0;
	/** @var int */ public const OPSYS_VSE=0;
	/** @var int */ public const OPSYS_ACORN_RISC=0;
	/** @var int */ public const OPSYS_VFAT=0;
	/** @var int */ public const OPSYS_ALTERNATE_MVS=0;
	/** @var int */ public const OPSYS_BEOS=0;
	/** @var int */ public const OPSYS_TANDEM=0;
	/** @var int */ public const OPSYS_OS_400=0;
	/** @var int */ public const OPSYS_OS_X=0;
	/** @var int */ public const OPSYS_DEFAULT=0;
	/** @var int */ public const EM_NONE=0;
	/** @var int */ public const EM_TRAD_PKWARE=0;
	/** @var int */ public const EM_AES_128=0;
	/** @var int */ public const EM_AES_192=0;
	/** @var int */ public const EM_AES_256=0;
	/** @var int */ public const EM_UNKNOWN=0;
	/** @var string */ public const LIBZIP_VERSION="0";
	/** @var int */ public const LENGTH_TO_END=0;
	/** @var int */ public const LENGTH_UNCHECKED=0;

	public readonly int $lastId;
	public readonly int $status;
	public readonly int $statusSys;
	public readonly int $numFiles;
	public readonly string $filename;
	public readonly string $comment;

	public function addEmptyDir(string $dirname, int $flags=0): bool;
	public function addFile(
		string $filepath,
		string $entryname="",
		int $start=0,
		int $length=ZipArchive::LENGTH_TO_END,
		int $flags=ZipArchive::FL_OVERWRITE
	): bool;
	public function addFromString(string $name, string $content, int $flags=ZipArchive::FL_OVERWRITE): bool;
	public function addGlob(string $pattern, int $flags=0, array $options=[]): array|false;
	public function addPattern(string $pattern, string $path=".", array $options=[]): array|false;
	public function clearError(): void;
	public function close(): bool;
	public function count(): int;
	public function deleteIndex(int $index): bool;
	public function deleteName(string $name): bool;
	public function extractTo(string $pathto, array|string|null $files=null): bool;
	public function getArchiveComment(int $flags=0): string|false;
	public function getArchiveFlag(int $flag, int $flags=0): int;
	public function getCommentIndex(int $index, int $flags=0): string|false;
	public function getCommentName(string $name, int $flags=0): string|false;
	public function getExternalAttributesIndex(
		int $index,
		int &$opsys,
		int &$attr,
		int $flags=0
	): bool;
	public function getExternalAttributesName(
		string $name,
		int &$opsys,
		int &$attr,
		int $flags=0
	): bool;
	public function getFromIndex(int $index, int $len=0, int $flags=0): string|false;
	public function getFromName(string $name, int $len=0, int $flags=0): string|false;
	public function getNameIndex(int $index, int $flags=0): string|false;
	public function getStatusString(): string;
	public function getStream(string $name): resource|false;
	public function getStreamIndex(int $index, int $flags=0): resource|false;
	public function getStreamName(string $name, int $flags=0): resource|false;
	public static function isCompressionMethodSupported(int $method, bool $enc=true): bool;
	public static function isEncryptionMethodSupported(int $method, bool $enc=true): bool;
	public function locateName(string $name, int $flags=0): int|false;
	public function open(string $filename, int $flags=0): bool|int;
	public function registerCancelCallback(callable $callback): bool;
	public function registerProgressCallback(float $rate, callable $callback): bool;
	public function renameIndex(int $index, string $new_name): bool;
	public function renameName(string $name, string $new_name): bool;
	public function replaceFile(
		string $filepath,
		int $index,
		int $start=0,
		int $length=ZipArchive::LENGTH_TO_END,
		int $flags=0
	): bool;
	public function setArchiveComment(string $comment): bool;
	public function setArchiveFlag(int $flag, int $value): bool;
	public function setCommentIndex(int $index, string $comment): bool;
	public function setCommentName(string $name, string $comment): bool;
	public function setCompressionIndex(int $index, int $method, int $compflags=0): bool;
	public function setCompressionName(string $name, int $method, int $compflags=0): bool;
	public function setEncryptionIndex(int $index, int $method, #[\SensitiveParameter] ?string $password=null): bool;
	public function setEncryptionName(string $name, int $method, #[\SensitiveParameter] ?string $password=null): bool;
	public function setExternalAttributesIndex(
		int $index,
		int $opsys,
		int $attr,
		int $flags=0
	): bool;
	public function setExternalAttributesName(
		string $name,
		int $opsys,
		int $attr,
		int $flags=0
	): bool;
	public function setMtimeIndex(int $index, int $timestamp, int $flags=0): bool;
	public function setMtimeName(string $name, int $timestamp, int $flags=0): bool;
	public function setPassword(string $password): bool;
	public function statIndex(int $index, int $flags=0): array|false;
	public function statName(string $name, int $flags=0): array|false;
	public function unchangeAll(): bool;
	public function unchangeArchive(): bool;
	public function unchangeIndex(int $index): bool;
	public function unchangeName(string $name): bool;
}
