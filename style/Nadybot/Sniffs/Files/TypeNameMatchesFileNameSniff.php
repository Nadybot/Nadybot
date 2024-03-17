<?php declare(strict_types=1);

namespace PHP_CodeSniffer\Standards\Nadybot\Sniffs\Files;

use const DIRECTORY_SEPARATOR;
use const T_STRING;
use function count;
use function explode;
use function min;
use function sprintf;
use function str_replace;
use function strcasecmp;
use function strlen;
use function substr;
use function ucfirst;
use function uksort;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

// phpcs:ignoreFile
class TypeNameMatchesFileNameSniff implements Sniff {
	public const CODE_NO_MATCH_BETWEEN_TYPE_NAME_AND_FILE_NAME = 'NoMatchBetweenTypeNameAndFileName';

	/** @var array<string, string> */
	public $rootNamespaces = [];

	/** @var list<string> */
	public $skipDirs = [];

	/** @var list<string> */
	public $ignoredNamespaces = [];

	/** @var list<string> */
	public array $extensions = ['php'];

	/** @var array<string, string>|null */
	private ?array $normalizedRootNamespaces=null;

	/** @var list<string>|null */
	private ?array $normalizedSkipDirs=null;

	/** @var list<string>|null */
	private ?array $normalizedIgnoredNamespaces=null;

	/** @var list<string>|null */
	private ?array $normalizedExtensions=null;

	private ?FilepathNamespaceExtractor $namespaceExtractor=null;

	/** @return array<int, (int|string)> */
	public function register(): array {
		return TokenHelper::$typeKeywordTokenCodes;
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 *
	 * @param int $typePointer
	 */
	public function process(File $phpcsFile, $typePointer): void {
		$tokens = $phpcsFile->getTokens();

		/** @var int $namePointer */
		$namePointer = TokenHelper::findNext($phpcsFile, T_STRING, $typePointer + 1);

		$typeName = NamespaceHelper::normalizeToCanonicalName(ClassHelper::getFullyQualifiedName($phpcsFile, $typePointer));

		foreach ($this->getIgnoredNamespaces() as $ignoredNamespace) {
			if (!str_starts_with($typeName, $ignoredNamespace . '\\')) {
				continue;
			}

			return;
		}

		$filename = str_replace('/', DIRECTORY_SEPARATOR, $phpcsFile->getFilename());
		$basePath = str_replace('/', DIRECTORY_SEPARATOR, $phpcsFile->config->basepath ?? '');
		if ($basePath !== '' && str_starts_with($filename, $basePath)) {
			$filename = substr($filename, strlen($basePath));
		}

		$expectedTypeName = $this->getNamespaceExtractor()->getTypeNameFromProjectPath($filename);
		if ($typeName === $expectedTypeName) {
			return;
		}

		$phpcsFile->addError(
			sprintf(
				'%s name %s does not match filepath %s.',
				ucfirst($tokens[$typePointer]['content']),
				$typeName,
				$phpcsFile->getFilename()
			),
			$namePointer,
			self::CODE_NO_MATCH_BETWEEN_TYPE_NAME_AND_FILE_NAME
		);
	}

	/** @return array<string, string> path(string) => namespace */
	private function getRootNamespaces(): array {
		if ($this->normalizedRootNamespaces === null) {
			/** @var array<string, string> $normalizedRootNamespaces */
			$normalizedRootNamespaces = SniffSettingsHelper::normalizeAssociativeArray($this->rootNamespaces);
			$this->normalizedRootNamespaces = $normalizedRootNamespaces;
			uksort($this->normalizedRootNamespaces, static function (string $a, string $b): int {
				$aParts = explode('/', str_replace('\\', '/', $a));
				$bParts = explode('/', str_replace('\\', '/', $b));

				$minPartsCount = min(count($aParts), count($bParts));
				for ($i = 0; $i < $minPartsCount; $i++) {
					$comparison = strcasecmp($bParts[$i], $aParts[$i]);
					if ($comparison === 0) {
						continue;
					}

					return $comparison;
				}

				return count($bParts) <=> count($aParts);
			});
		}

		return $this->normalizedRootNamespaces;
	}

	/** @return list<string> */
	private function getSkipDirs(): array {
		if ($this->normalizedSkipDirs === null) {
			$this->normalizedSkipDirs = SniffSettingsHelper::normalizeArray($this->skipDirs);
		}

		return $this->normalizedSkipDirs;
	}

	/** @return list<string> */
	private function getIgnoredNamespaces(): array {
		if ($this->normalizedIgnoredNamespaces === null) {
			$this->normalizedIgnoredNamespaces = SniffSettingsHelper::normalizeArray($this->ignoredNamespaces);
		}

		return $this->normalizedIgnoredNamespaces;
	}

	/** @return list<string> */
	private function getExtensions(): array {
		if ($this->normalizedExtensions === null) {
			$this->normalizedExtensions = SniffSettingsHelper::normalizeArray($this->extensions);
		}

		return $this->normalizedExtensions;
	}

	private function getNamespaceExtractor(): FilepathNamespaceExtractor {
		if ($this->namespaceExtractor === null) {
			$this->namespaceExtractor = new FilepathNamespaceExtractor(
				$this->getRootNamespaces(),
				$this->getSkipDirs(),
				$this->getExtensions()
			);
		}

		return $this->namespaceExtractor;
	}
}

class TokenHelper {
	/** @var array<int, (int|string)> */
	public static $arrayTokenCodes = [
		T_ARRAY,
		T_OPEN_SHORT_ARRAY,
	];

	/** @var array<int, (int|string)> */
	public static $typeKeywordTokenCodes = [
		T_CLASS,
		T_TRAIT,
		T_INTERFACE,
		T_ENUM,
	];

	/** @var array<int, (int|string)> */
	public static $ineffectiveTokenCodes = [
		T_WHITESPACE,
		T_COMMENT,
		T_DOC_COMMENT,
		T_DOC_COMMENT_OPEN_TAG,
		T_DOC_COMMENT_CLOSE_TAG,
		T_DOC_COMMENT_STAR,
		T_DOC_COMMENT_STRING,
		T_DOC_COMMENT_TAG,
		T_DOC_COMMENT_WHITESPACE,
		T_PHPCS_DISABLE,
		T_PHPCS_ENABLE,
		T_PHPCS_IGNORE,
		T_PHPCS_IGNORE_FILE,
		T_PHPCS_SET,
	];

	/** @var array<int, (int|string)> */
	public static $annotationTokenCodes = [
		T_DOC_COMMENT_TAG,
		T_PHPCS_DISABLE,
		T_PHPCS_ENABLE,
		T_PHPCS_IGNORE,
		T_PHPCS_IGNORE_FILE,
		T_PHPCS_SET,
	];

	/** @var array<int, (int|string)> */
	public static $inlineCommentTokenCodes = [
		T_COMMENT,
		T_PHPCS_DISABLE,
		T_PHPCS_ENABLE,
		T_PHPCS_IGNORE,
		T_PHPCS_IGNORE_FILE,
		T_PHPCS_SET,
	];

	/** @var array<int, (int|string)> */
	public static $earlyExitTokenCodes = [
		T_RETURN,
		T_CONTINUE,
		T_BREAK,
		T_THROW,
		T_EXIT,
	];

	/** @var array<int, (int|string)> */
	public static $functionTokenCodes = [
		T_FUNCTION,
		T_CLOSURE,
		T_FN,
	];

	/** @var array<int, (int|string)> */
	public static $propertyModifiersTokenCodes = [
		T_VAR,
		T_PUBLIC,
		T_PROTECTED,
		T_PRIVATE,
		T_READONLY,
		T_STATIC,
	];

	/** @param int|string|array<int|string, int|string> $types */
	public static function findNext(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findNext($types, $startPointer, $endPointer, false);
		return $token === false ? null : $token;
	}

	/**
	 * @param int|string|array<int|string, int|string> $types
	 *
	 * @return list<int>
	 */
	public static function findNextAll(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): array {
		$pointers = [];

		$actualStartPointer = $startPointer;
		while (true) {
			$pointer = self::findNext($phpcsFile, $types, $actualStartPointer, $endPointer);
			if ($pointer === null) {
				break;
			}

			$pointers[] = $pointer;
			$actualStartPointer = $pointer + 1;
		}

		return $pointers;
	}

	/** @param int|string|array<int|string, int|string> $types */
	public static function findNextContent(File $phpcsFile, $types, string $content, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findNext($types, $startPointer, $endPointer, false, $content);
		return $token === false ? null : $token;
	}

	/**
	 * @param int      $startPointer Search starts at this token, inclusive
	 * @param int|null $endPointer   Search ends at this token, exclusive
	 */
	public static function findNextEffective(File $phpcsFile, int $startPointer, ?int $endPointer=null): ?int {
		return self::findNextExcluding($phpcsFile, self::$ineffectiveTokenCodes, $startPointer, $endPointer);
	}

	/**
	 * @param int      $startPointer Search starts at this token, inclusive
	 * @param int|null $endPointer   Search ends at this token, exclusive
	 */
	public static function findNextNonWhitespace(File $phpcsFile, int $startPointer, ?int $endPointer=null): ?int {
		return self::findNextExcluding($phpcsFile, T_WHITESPACE, $startPointer, $endPointer);
	}

	/**
	 * @param int|string|array<int|string, int|string> $types
	 * @param int                                      $startPointer Search starts at this token, inclusive
	 * @param int|null                                 $endPointer   Search ends at this token, exclusive
	 */
	public static function findNextExcluding(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findNext($types, $startPointer, $endPointer, true);
		return $token === false ? null : $token;
	}

	/** @param int|string|array<int|string, int|string> $types */
	public static function findNextLocal(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findNext($types, $startPointer, $endPointer, false, null, true);
		return $token === false ? null : $token;
	}

	/**
	 * @param int      $startPointer Search starts at this token, inclusive
	 * @param int|null $endPointer   Search ends at this token, exclusive
	 */
	public static function findNextAnyToken(File $phpcsFile, int $startPointer, ?int $endPointer=null): ?int {
		return self::findNextExcluding($phpcsFile, [], $startPointer, $endPointer);
	}

	/**
	 * @param int|string|array<int|string, int|string> $types
	 * @param int                                      $startPointer Search starts at this token, inclusive
	 * @param int|null                                 $endPointer   Search ends at this token, exclusive
	 */
	public static function findPrevious(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findPrevious($types, $startPointer, $endPointer, false);
		return $token === false ? null : $token;
	}

	/** @param int|string|array<int|string, int|string> $types */
	public static function findPreviousContent(File $phpcsFile, $types, string $content, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findPrevious($types, $startPointer, $endPointer, false, $content);
		return $token === false ? null : $token;
	}

	/**
	 * @param int      $startPointer Search starts at this token, inclusive
	 * @param int|null $endPointer   Search ends at this token, exclusive
	 */
	public static function findPreviousEffective(File $phpcsFile, int $startPointer, ?int $endPointer=null): ?int {
		return self::findPreviousExcluding($phpcsFile, self::$ineffectiveTokenCodes, $startPointer, $endPointer);
	}

	/**
	 * @param int      $startPointer Search starts at this token, inclusive
	 * @param int|null $endPointer   Search ends at this token, exclusive
	 */
	public static function findPreviousNonWhitespace(File $phpcsFile, int $startPointer, ?int $endPointer=null): ?int {
		return self::findPreviousExcluding($phpcsFile, T_WHITESPACE, $startPointer, $endPointer);
	}

	/**
	 * @param int|string|array<int|string, int|string> $types
	 * @param int                                      $startPointer Search starts at this token, inclusive
	 * @param int|null                                 $endPointer   Search ends at this token, exclusive
	 */
	public static function findPreviousExcluding(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findPrevious($types, $startPointer, $endPointer, true);
		return $token === false ? null : $token;
	}

	/** @param int|string|array<int|string, int|string> $types */
	public static function findPreviousLocal(File $phpcsFile, $types, int $startPointer, ?int $endPointer=null): ?int {
		/** @var int|false $token */
		$token = $phpcsFile->findPrevious($types, $startPointer, $endPointer, false, null, true);
		return $token === false ? null : $token;
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findFirstTokenOnLine(File $phpcsFile, int $pointer): int {
		if ($pointer === 0) {
			return $pointer;
		}

		$tokens = $phpcsFile->getTokens();

		$line = $tokens[$pointer]['line'];

		do {
			$pointer--;
		} while ($tokens[$pointer]['line'] === $line);

		return $pointer + 1;
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findLastTokenOnLine(File $phpcsFile, int $pointer): int {
		$tokens = $phpcsFile->getTokens();

		$line = $tokens[$pointer]['line'];

		do {
			$pointer++;
		} while (array_key_exists($pointer, $tokens) && $tokens[$pointer]['line'] === $line);

		return $pointer - 1;
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findLastTokenOnPreviousLine(File $phpcsFile, int $pointer): int {
		$tokens = $phpcsFile->getTokens();

		$line = $tokens[$pointer]['line'];

		do {
			$pointer--;
		} while ($tokens[$pointer]['line'] === $line);

		return $pointer;
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findFirstTokenOnNextLine(File $phpcsFile, int $pointer): ?int {
		$tokens = $phpcsFile->getTokens();
		if ($pointer >= count($tokens)) {
			return null;
		}

		$line = $tokens[$pointer]['line'];

		do {
			$pointer++;
			if (!array_key_exists($pointer, $tokens)) {
				return null;
			}
		} while ($tokens[$pointer]['line'] === $line);

		return $pointer;
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findFirstNonWhitespaceOnLine(File $phpcsFile, int $pointer): int {
		if ($pointer === 0) {
			return $pointer;
		}

		$tokens = $phpcsFile->getTokens();

		$line = $tokens[$pointer]['line'];

		do {
			$pointer--;
		} while ($pointer >= 0 && $tokens[$pointer]['line'] === $line);

		return self::findNextExcluding($phpcsFile, [T_WHITESPACE, T_DOC_COMMENT_WHITESPACE], $pointer + 1);
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findFirstNonWhitespaceOnNextLine(File $phpcsFile, int $pointer): ?int {
		$newLinePointer = self::findNextContent($phpcsFile, [T_WHITESPACE, T_DOC_COMMENT_WHITESPACE], $phpcsFile->eolChar, $pointer);
		if ($newLinePointer === null) {
			return null;
		}

		$nextPointer = self::findNextExcluding($phpcsFile, [T_WHITESPACE, T_DOC_COMMENT_WHITESPACE], $newLinePointer + 1);

		$tokens = $phpcsFile->getTokens();
		if ($nextPointer !== null && $tokens[$pointer]['line'] === $tokens[$nextPointer]['line'] - 1) {
			return $nextPointer;
		}

		return null;
	}

	/** @param int $pointer Search starts at this token, inclusive */
	public static function findFirstNonWhitespaceOnPreviousLine(File $phpcsFile, int $pointer): ?int {
		$newLinePointerOnPreviousLine = self::findPreviousContent(
			$phpcsFile,
			[T_WHITESPACE, T_DOC_COMMENT_WHITESPACE],
			$phpcsFile->eolChar,
			$pointer
		);
		if ($newLinePointerOnPreviousLine === null) {
			return null;
		}

		$newLinePointerBeforePreviousLine = self::findPreviousContent(
			$phpcsFile,
			[T_WHITESPACE, T_DOC_COMMENT_WHITESPACE],
			$phpcsFile->eolChar,
			$newLinePointerOnPreviousLine - 1
		);
		if ($newLinePointerBeforePreviousLine === null) {
			return null;
		}

		$nextPointer = self::findNextExcluding($phpcsFile, [T_WHITESPACE, T_DOC_COMMENT_WHITESPACE], $newLinePointerBeforePreviousLine + 1);

		$tokens = $phpcsFile->getTokens();
		if ($nextPointer !== null && $tokens[$pointer]['line'] === $tokens[$nextPointer]['line'] + 1) {
			return $nextPointer;
		}

		return null;
	}

	public static function getContent(File $phpcsFile, int $startPointer, ?int $endPointer=null): string {
		$tokens = $phpcsFile->getTokens();
		$endPointer ??= self::getLastTokenPointer($phpcsFile);

		$content = '';
		for ($i = $startPointer; $i <= $endPointer; $i++) {
			$content .= $tokens[$i]['content'];
		}

		return $content;
	}

	public static function getLastTokenPointer(File $phpcsFile): int {
		$tokenCount = count($phpcsFile->getTokens());
		if ($tokenCount === 0) {
			throw new \Exception($phpcsFile->getFilename());
		}
		return $tokenCount - 1;
	}

	/** @return array<int, (int|string)> */
	public static function getNameTokenCodes(): array {
		return [T_STRING, T_NS_SEPARATOR, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE];
	}

	/** @return array<int, (int|string)> */
	public static function getOnlyNameTokenCodes(): array {
		return [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE];
	}

	/** @return array<int, (int|string)> */
	public static function getOnlyTypeHintTokenCodes(): array {
		static $typeHintTokenCodes = null;

		if ($typeHintTokenCodes === null) {
			$typeHintTokenCodes = array_merge(
				self::getNameTokenCodes(),
				[
					T_SELF,
					T_PARENT,
					T_ARRAY_HINT,
					T_CALLABLE,
					T_FALSE,
					T_TRUE,
					T_NULL,
				]
			);
		}

		return $typeHintTokenCodes;
	}

	/** @return array<int, (int|string)> */
	public static function getTypeHintTokenCodes(): array {
		static $typeHintTokenCodes = null;

		if ($typeHintTokenCodes === null) {
			$typeHintTokenCodes = array_merge(
				self::getOnlyTypeHintTokenCodes(),
				[T_TYPE_UNION, T_TYPE_INTERSECTION]
			);
		}

		return $typeHintTokenCodes;
	}
}

class FilepathNamespaceExtractor {
	/** @var array<string, string> */
	private $rootNamespaces;

	/** @var array<string, bool> dir(string) => true(bool) */
	private $skipDirs;

	/** @var list<string> */
	private $extensions;

	/**
	 * @param array<string, string> $rootNamespaces directory(string) => namespace
	 * @param list<string>          $skipDirs
	 * @param list<string>          $extensions     index(integer) => extension
	 */
	public function __construct(array $rootNamespaces, array $skipDirs, array $extensions) {
		$this->rootNamespaces = $rootNamespaces;
		$this->skipDirs = array_fill_keys($skipDirs, true);
		$this->extensions = array_map(static function (string $extension): string {
			return strtolower($extension);
		}, $extensions);
	}

	public function getTypeNameFromProjectPath(string $path): ?string {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!in_array($extension, $this->extensions, true)) {
			return null;
		}

		/** @var list<string> $pathParts */
		$pathParts = \Safe\preg_split('~[/\\\]~', $path);
		$rootNamespace = null;
		while (count($pathParts) > 0) {
			array_shift($pathParts);
			foreach ($this->rootNamespaces as $directory => $namespace) {
				if (!str_starts_with(implode('/', $pathParts) . '/', $directory . '/')) {
					continue;
				}

				$directoryPartsCount = count(explode('/', $directory));
				for ($i = 0; $i < $directoryPartsCount; $i++) {
					array_shift($pathParts);
				}

				$rootNamespace = $namespace;
				break 2;
			}
		}

		if ($rootNamespace === null) {
			return null;
		}

		array_unshift($pathParts, $rootNamespace);

		$typeName = implode('\\', array_filter($pathParts, function (string $pathPart): bool {
			return !isset($this->skipDirs[$pathPart]);
		}));

		return substr($typeName, 0, -strlen('.' . $extension));
	}
}

class NamespaceHelper {
	public const NAMESPACE_SEPARATOR = '\\';

	/** @return list<int> */
	public static function getAllNamespacesPointers(File $phpcsFile): array {
		$tokens = $phpcsFile->getTokens();
		$lazyValue = static function () use ($phpcsFile, $tokens): array {
			$all = TokenHelper::findNextAll($phpcsFile, T_NAMESPACE, 0);
			$all = array_filter(
				$all,
				static function ($pointer) use ($phpcsFile, $tokens) {
					$next = TokenHelper::findNextEffective($phpcsFile, $pointer + 1);
					return $next === null || $tokens[$next]['code'] !== T_NS_SEPARATOR;
				}
			);

			return array_values($all);
		};

		return SniffLocalCache::getAndSetIfNotCached($phpcsFile, 'namespacePointers', $lazyValue);
	}

	public static function isFullyQualifiedName(string $typeName): bool {
		return str_starts_with($typeName, self::NAMESPACE_SEPARATOR);
	}

	public static function isFullyQualifiedPointer(File $phpcsFile, int $pointer): bool {
		return in_array($phpcsFile->getTokens()[$pointer]['code'], [T_NS_SEPARATOR, T_NAME_FULLY_QUALIFIED], true);
	}

	public static function getFullyQualifiedTypeName(string $typeName): string {
		if (self::isFullyQualifiedName($typeName)) {
			return $typeName;
		}

		return sprintf('%s%s', self::NAMESPACE_SEPARATOR, $typeName);
	}

	public static function hasNamespace(string $typeName): bool {
		$parts = self::getNameParts($typeName);

		return count($parts) > 1;
	}

	/** @return list<string> */
	public static function getNameParts(string $name): array {
		$name = self::normalizeToCanonicalName($name);

		return explode(self::NAMESPACE_SEPARATOR, $name);
	}

	public static function getLastNamePart(string $name): string {
		return array_slice(self::getNameParts($name), -1)[0];
	}

	public static function getName(File $phpcsFile, int $namespacePointer): string {
		/** @var int $namespaceNameStartPointer */
		$namespaceNameStartPointer = TokenHelper::findNextEffective($phpcsFile, $namespacePointer + 1);
		$namespaceNameEndPointer = TokenHelper::findNextExcluding(
			$phpcsFile,
			TokenHelper::getNameTokenCodes(),
			$namespaceNameStartPointer + 1
		) - 1;

		return TokenHelper::getContent($phpcsFile, $namespaceNameStartPointer, $namespaceNameEndPointer);
	}

	public static function findCurrentNamespacePointer(File $phpcsFile, int $pointer): ?int {
		$allNamespacesPointers = array_reverse(self::getAllNamespacesPointers($phpcsFile));
		foreach ($allNamespacesPointers as $namespacesPointer) {
			if ($namespacesPointer < $pointer) {
				return $namespacesPointer;
			}
		}

		return null;
	}

	public static function findCurrentNamespaceName(File $phpcsFile, int $anyPointer): ?string {
		$namespacePointer = self::findCurrentNamespacePointer($phpcsFile, $anyPointer);
		if ($namespacePointer === null) {
			return null;
		}

		return self::getName($phpcsFile, $namespacePointer);
	}

	public static function getUnqualifiedNameFromFullyQualifiedName(string $name): string {
		$parts = self::getNameParts($name);
		return $parts[count($parts) - 1];
	}

	public static function isQualifiedName(string $name): bool {
		return strpos($name, self::NAMESPACE_SEPARATOR) !== false;
	}

	public static function normalizeToCanonicalName(string $fullyQualifiedName): string {
		return ltrim($fullyQualifiedName, self::NAMESPACE_SEPARATOR);
	}

	public static function isTypeInNamespace(string $typeName, string $namespace): bool {
		return str_starts_with(
			self::normalizeToCanonicalName($typeName) . '\\',
			$namespace . '\\'
		);
	}

	public static function resolveClassName(File $phpcsFile, string $nameAsReferencedInFile, int $currentPointer): string {
		return self::resolveName($phpcsFile, $nameAsReferencedInFile, ReferencedName::TYPE_CLASS, $currentPointer);
	}

	public static function resolveName(File $phpcsFile, string $nameAsReferencedInFile, string $type, int $currentPointer): string {
		if (self::isFullyQualifiedName($nameAsReferencedInFile)) {
			return $nameAsReferencedInFile;
		}

		$useStatements = UseStatementHelper::getUseStatementsForPointer($phpcsFile, $currentPointer);

		$uniqueId = UseStatement::getUniqueId($type, self::normalizeToCanonicalName($nameAsReferencedInFile));

		if (isset($useStatements[$uniqueId])) {
			return sprintf('%s%s', self::NAMESPACE_SEPARATOR, $useStatements[$uniqueId]->getFullyQualifiedTypeName());
		}

		$nameParts = self::getNameParts($nameAsReferencedInFile);
		$firstPartUniqueId = UseStatement::getUniqueId($type, $nameParts[0]);
		if (count($nameParts) > 1 && isset($useStatements[$firstPartUniqueId])) {
			return sprintf(
				'%s%s%s%s',
				self::NAMESPACE_SEPARATOR,
				$useStatements[$firstPartUniqueId]->getFullyQualifiedTypeName(),
				self::NAMESPACE_SEPARATOR,
				implode(self::NAMESPACE_SEPARATOR, array_slice($nameParts, 1))
			);
		}

		$name = sprintf('%s%s', self::NAMESPACE_SEPARATOR, $nameAsReferencedInFile);

		if ($type === ReferencedName::TYPE_CONSTANT && defined($name)) {
			return $name;
		}

		$namespaceName = self::findCurrentNamespaceName($phpcsFile, $currentPointer);
		if ($namespaceName !== null) {
			$name = sprintf('%s%s%s', self::NAMESPACE_SEPARATOR, $namespaceName, $name);
		}
		return $name;
	}
}

class ReferencedName {
	public const TYPE_CLASS = 'class';
	public const TYPE_FUNCTION = 'function';
	public const TYPE_CONSTANT = 'constant';

	/** @var string */
	private $nameAsReferencedInFile;

	/** @var int */
	private $startPointer;

	/** @var int */
	private $endPointer;

	/** @var string */
	private $type;

	public function __construct(string $nameAsReferencedInFile, int $startPointer, int $endPointer, string $type) {
		$this->nameAsReferencedInFile = $nameAsReferencedInFile;
		$this->startPointer = $startPointer;
		$this->endPointer = $endPointer;
		$this->type = $type;
	}

	public function getNameAsReferencedInFile(): string {
		return $this->nameAsReferencedInFile;
	}

	public function getStartPointer(): int {
		return $this->startPointer;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getEndPointer(): int {
		return $this->endPointer;
	}

	public function isClass(): bool {
		return $this->type === self::TYPE_CLASS;
	}

	public function isConstant(): bool {
		return $this->type === self::TYPE_CONSTANT;
	}

	public function isFunction(): bool {
		return $this->type === self::TYPE_FUNCTION;
	}

	public function hasSameUseStatementType(UseStatement $useStatement): bool {
		return $this->getType() === $useStatement->getType();
	}
}

class UseStatement {
	public const TYPE_CLASS = ReferencedName::TYPE_CLASS;
	public const TYPE_FUNCTION = ReferencedName::TYPE_FUNCTION;
	public const TYPE_CONSTANT = ReferencedName::TYPE_CONSTANT;

	/** @var string */
	private $nameAsReferencedInFile;

	/** @var string */
	private $normalizedNameAsReferencedInFile;

	/** @var string */
	private $fullyQualifiedTypeName;

	/** @var int */
	private $usePointer;

	/** @var string */
	private $type;

	/** @var string|null */
	private $alias;

	public function __construct(
		string $nameAsReferencedInFile,
		string $fullyQualifiedClassName,
		int $usePointer,
		string $type,
		?string $alias
	) {
		$this->nameAsReferencedInFile = $nameAsReferencedInFile;
		$this->normalizedNameAsReferencedInFile = self::normalizedNameAsReferencedInFile($type, $nameAsReferencedInFile);
		$this->fullyQualifiedTypeName = $fullyQualifiedClassName;
		$this->usePointer = $usePointer;
		$this->type = $type;
		$this->alias = $alias;
	}

	public function getNameAsReferencedInFile(): string {
		return $this->nameAsReferencedInFile;
	}

	public function getCanonicalNameAsReferencedInFile(): string {
		return $this->normalizedNameAsReferencedInFile;
	}

	public function getFullyQualifiedTypeName(): string {
		return $this->fullyQualifiedTypeName;
	}

	public function getPointer(): int {
		return $this->usePointer;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getAlias(): ?string {
		return $this->alias;
	}

	public function isClass(): bool {
		return $this->type === self::TYPE_CLASS;
	}

	public function isConstant(): bool {
		return $this->type === self::TYPE_CONSTANT;
	}

	public function isFunction(): bool {
		return $this->type === self::TYPE_FUNCTION;
	}

	public function hasSameType(self $that): bool {
		return $this->type === $that->type;
	}

	public static function getUniqueId(string $type, string $name): string {
		$normalizedName = self::normalizedNameAsReferencedInFile($type, $name);

		if ($type === self::TYPE_CLASS) {
			return $normalizedName;
		}

		return sprintf('%s %s', $type, $normalizedName);
	}

	public static function normalizedNameAsReferencedInFile(string $type, string $name): string {
		if ($type === self::TYPE_CONSTANT) {
			return $name;
		}

		return strtolower($name);
	}

	public static function getTypeName(string $type): ?string {
		if ($type === self::TYPE_CONSTANT) {
			return 'const';
		}

		if ($type === self::TYPE_FUNCTION) {
			return 'function';
		}

		return null;
	}
}
class UseStatementHelper {
	public static function isAnonymousFunctionUse(File $phpcsFile, int $usePointer): bool {
		$tokens = $phpcsFile->getTokens();
		$nextPointer = TokenHelper::findNextEffective($phpcsFile, $usePointer + 1);
		$nextToken = $tokens[$nextPointer];

		return $nextToken['code'] === T_OPEN_PARENTHESIS;
	}

	public static function isTraitUse(File $phpcsFile, int $usePointer): bool {
		$typePointer = TokenHelper::findPrevious($phpcsFile, array_merge(TokenHelper::$typeKeywordTokenCodes, [T_ANON_CLASS]), $usePointer);
		if ($typePointer !== null) {
			$tokens = $phpcsFile->getTokens();
			$typeToken = $tokens[$typePointer];
			$openerPointer = $typeToken['scope_opener'];
			$closerPointer = $typeToken['scope_closer'];

			return $usePointer > $openerPointer && $usePointer < $closerPointer
				&& !self::isAnonymousFunctionUse($phpcsFile, $usePointer);
		}

		return false;
	}

	public static function getAlias(File $phpcsFile, int $usePointer): ?string {
		$endPointer = TokenHelper::findNext($phpcsFile, [T_SEMICOLON, T_COMMA], $usePointer + 1);
		$asPointer = TokenHelper::findNext($phpcsFile, T_AS, $usePointer + 1, $endPointer);

		if ($asPointer === null) {
			return null;
		}

		$tokens = $phpcsFile->getTokens();
		return $tokens[TokenHelper::findNext($phpcsFile, T_STRING, $asPointer + 1)]['content'];
	}

	public static function getNameAsReferencedInClassFromUse(File $phpcsFile, int $usePointer): string {
		$alias = self::getAlias($phpcsFile, $usePointer);
		if ($alias !== null) {
			return $alias;
		}

		$name = self::getFullyQualifiedTypeNameFromUse($phpcsFile, $usePointer);
		return NamespaceHelper::getUnqualifiedNameFromFullyQualifiedName($name);
	}

	public static function getFullyQualifiedTypeNameFromUse(File $phpcsFile, int $usePointer): string {
		$tokens = $phpcsFile->getTokens();

		$nameEndPointer = TokenHelper::findNext($phpcsFile, [T_SEMICOLON, T_AS, T_COMMA], $usePointer + 1) - 1;
		if (in_array($tokens[$nameEndPointer]['code'], TokenHelper::$ineffectiveTokenCodes, true)) {
			$nameEndPointer = TokenHelper::findPreviousEffective($phpcsFile, $nameEndPointer);
		}
		$nameStartPointer = TokenHelper::findPreviousExcluding($phpcsFile, TokenHelper::getNameTokenCodes(), $nameEndPointer - 1) + 1;

		$name = TokenHelper::getContent($phpcsFile, $nameStartPointer, $nameEndPointer);

		return NamespaceHelper::normalizeToCanonicalName($name);
	}

	/** @return array<string, UseStatement> */
	public static function getUseStatementsForPointer(File $phpcsFile, int $pointer): array {
		$allUseStatements = self::getFileUseStatements($phpcsFile);

		if (count($allUseStatements) === 1) {
			return current($allUseStatements);
		}

		foreach (array_reverse($allUseStatements, true) as $pointerBeforeUseStatements => $useStatements) {
			if ($pointerBeforeUseStatements < $pointer) {
				return $useStatements;
			}
		}

		return [];
	}

	/** @return array<int, array<string, UseStatement>> */
	public static function getFileUseStatements(File $phpcsFile): array {
		$lazyValue = static function () use ($phpcsFile): array {
			$useStatements = [];
			$tokens = $phpcsFile->getTokens();

			$namespaceAndOpenTagPointers = TokenHelper::findNextAll($phpcsFile, [T_OPEN_TAG, T_NAMESPACE], 0);
			$openTagPointer = $namespaceAndOpenTagPointers[0];

			foreach (self::getUseStatementPointers($phpcsFile, $openTagPointer) as $usePointer) {
				$pointerBeforeUseStatements = $openTagPointer;
				if (count($namespaceAndOpenTagPointers) > 1) {
					foreach (array_reverse($namespaceAndOpenTagPointers) as $namespaceAndOpenTagPointer) {
						if ($namespaceAndOpenTagPointer < $usePointer) {
							$pointerBeforeUseStatements = $namespaceAndOpenTagPointer;
							break;
						}
					}
				}

				$nextTokenFromUsePointer = TokenHelper::findNextEffective($phpcsFile, $usePointer + 1);
				$type = UseStatement::TYPE_CLASS;
				if ($tokens[$nextTokenFromUsePointer]['code'] === T_STRING) {
					if ($tokens[$nextTokenFromUsePointer]['content'] === 'const') {
						$type = UseStatement::TYPE_CONSTANT;
					} elseif ($tokens[$nextTokenFromUsePointer]['content'] === 'function') {
						$type = UseStatement::TYPE_FUNCTION;
					}
				}
				$name = self::getNameAsReferencedInClassFromUse($phpcsFile, $usePointer);
				$useStatement = new UseStatement(
					$name,
					self::getFullyQualifiedTypeNameFromUse($phpcsFile, $usePointer),
					$usePointer,
					$type,
					self::getAlias($phpcsFile, $usePointer)
				);
				$useStatements[$pointerBeforeUseStatements][UseStatement::getUniqueId($type, $name)] = $useStatement;
			}

			return $useStatements;
		};

		return SniffLocalCache::getAndSetIfNotCached($phpcsFile, 'useStatements', $lazyValue);
	}

	public static function getUseStatementPointer(File $phpcsFile, int $pointer): ?int {
		$pointers = self::getUseStatementPointers($phpcsFile, 0);

		foreach (array_reverse($pointers) as $pointerBeforeUseStatements) {
			if ($pointerBeforeUseStatements < $pointer) {
				return $pointerBeforeUseStatements;
			}
		}

		return null;
	}

	/**
	 * Searches for all use statements in a file, skips bodies of classes and traits.
	 *
	 * @return list<int>
	 */
	private static function getUseStatementPointers(File $phpcsFile, int $openTagPointer): array {
		$lazy = static function () use ($phpcsFile, $openTagPointer): array {
			$tokens = $phpcsFile->getTokens();
			$pointer = $openTagPointer + 1;
			$pointers = [];
			while (true) {
				$typesToFind = array_merge([T_USE], TokenHelper::$typeKeywordTokenCodes);
				$pointer = TokenHelper::findNext($phpcsFile, $typesToFind, $pointer);
				if ($pointer === null) {
					break;
				}

				$token = $tokens[$pointer];
				if (in_array($token['code'], TokenHelper::$typeKeywordTokenCodes, true)) {
					$pointer = $token['scope_closer'] + 1;
					continue;
				}

				if (self::isGroupUse($phpcsFile, $pointer)) {
					$pointer++;
					continue;
				}

				if (self::isAnonymousFunctionUse($phpcsFile, $pointer)) {
					$pointer++;
					continue;
				}

				$pointers[] = $pointer;
				$pointer++;
			}

			return $pointers;
		};

		return SniffLocalCache::getAndSetIfNotCached($phpcsFile, 'useStatementPointers', $lazy);
	}

	private static function isGroupUse(File $phpcsFile, int $usePointer): bool {
		$tokens = $phpcsFile->getTokens();
		$semicolonOrGroupUsePointer = TokenHelper::findNext($phpcsFile, [T_SEMICOLON, T_OPEN_USE_GROUP], $usePointer + 1);

		return $tokens[$semicolonOrGroupUsePointer]['code'] === T_OPEN_USE_GROUP;
	}
}

final class SniffLocalCache {
	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $cache = [];

	/** @phpcsSuppress SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint */
	public static function getAndSetIfNotCached(File $phpcsFile, string $key, \Closure $lazyValue) {
		$fixerLoops = $phpcsFile->fixer !== null ? $phpcsFile->fixer->loops : 0;
		$internalKey = sprintf('%s-%s', $phpcsFile->getFilename(), $key);

		self::setIfNotCached($fixerLoops, $internalKey, $lazyValue);

		return self::$cache[$fixerLoops][$internalKey] ?? null;
	}

	private static function setIfNotCached(int $fixerLoops, string $internalKey, \Closure $lazyValue): void {
		if (array_key_exists($fixerLoops, self::$cache) && array_key_exists($internalKey, self::$cache[$fixerLoops])) {
			return;
		}

		self::$cache[$fixerLoops][$internalKey] = $lazyValue();

		if ($fixerLoops > 0) {
			unset(self::$cache[$fixerLoops - 1]);
		}
	}
}

class ClassHelper {
	public static function getClassPointer(File $phpcsFile, int $pointer): ?int {
		$tokens = $phpcsFile->getTokens();

		$classPointers = array_reverse(self::getAllClassPointers($phpcsFile));
		foreach ($classPointers as $classPointer) {
			if ($tokens[$classPointer]['scope_opener'] < $pointer && $tokens[$classPointer]['scope_closer'] > $pointer) {
				return $classPointer;
			}
		}

		return null;
	}

	public static function isFinal(File $phpcsFile, int $classPointer): bool {
		return $phpcsFile->getTokens()[TokenHelper::findPreviousEffective($phpcsFile, $classPointer - 1)]['code'] === T_FINAL;
	}

	public static function getFullyQualifiedName(File $phpcsFile, int $classPointer): string {
		$className = self::getName($phpcsFile, $classPointer);

		$tokens = $phpcsFile->getTokens();
		if ($tokens[$classPointer]['code'] === T_ANON_CLASS) {
			return $className;
		}

		$name = sprintf('%s%s', NamespaceHelper::NAMESPACE_SEPARATOR, $className);
		$namespace = NamespaceHelper::findCurrentNamespaceName($phpcsFile, $classPointer);
		return $namespace !== null ? sprintf('%s%s%s', NamespaceHelper::NAMESPACE_SEPARATOR, $namespace, $name) : $name;
	}

	public static function getName(File $phpcsFile, int $classPointer): string {
		$tokens = $phpcsFile->getTokens();

		if ($tokens[$classPointer]['code'] === T_ANON_CLASS) {
			return 'class@anonymous';
		}

		return $tokens[TokenHelper::findNext($phpcsFile, T_STRING, $classPointer + 1, $tokens[$classPointer]['scope_opener'])]['content'];
	}

	/** @return array<int, string> */
	public static function getAllNames(File $phpcsFile): array {
		$tokens = $phpcsFile->getTokens();

		$names = [];

		/** @var int $classPointer */
		foreach (self::getAllClassPointers($phpcsFile) as $classPointer) {
			if ($tokens[$classPointer]['code'] === T_ANON_CLASS) {
				continue;
			}

			$names[$classPointer] = self::getName($phpcsFile, $classPointer);
		}

		return $names;
	}

	/** @return list<int> */
	public static function getTraitUsePointers(File $phpcsFile, int $classPointer): array {
		$useStatements = [];

		$tokens = $phpcsFile->getTokens();

		$scopeLevel = $tokens[$classPointer]['level'] + 1;
		for ($i = $tokens[$classPointer]['scope_opener'] + 1; $i < $tokens[$classPointer]['scope_closer']; $i++) {
			if ($tokens[$i]['code'] !== T_USE) {
				continue;
			}

			if ($tokens[$i]['level'] !== $scopeLevel) {
				continue;
			}

			$useStatements[] = $i;
		}

		return $useStatements;
	}

	/** @return list<int> */
	private static function getAllClassPointers(File $phpcsFile): array {
		$lazyValue = static function () use ($phpcsFile): array {
			return TokenHelper::findNextAll($phpcsFile, array_merge(TokenHelper::$typeKeywordTokenCodes, [T_ANON_CLASS]), 0);
		};

		return SniffLocalCache::getAndSetIfNotCached($phpcsFile, 'classPointers', $lazyValue);
	}
}

class SniffSettingsHelper {
	/** @param string|int $settings */
	public static function normalizeInteger($settings): int {
		return (int)trim((string)$settings);
	}

	/** @param string|int|null $settings */
	public static function normalizeNullableInteger($settings): ?int {
		return $settings !== null ? (int)trim((string)$settings) : null;
	}

	/**
	 * @param list<string> $settings
	 *
	 * @return list<string>
	 */
	public static function normalizeArray(array $settings): array {
		$settings = array_map(static function (string $value): string {
			return trim($value);
		}, $settings);
		$settings = array_filter($settings, static function (string $value): bool {
			return $value !== '';
		});
		return array_values($settings);
	}

	/**
	 * @param array<int|string, int|string> $settings
	 *
	 * @return array<int|string, int|string>
	 */
	public static function normalizeAssociativeArray(array $settings): array {
		$normalizedSettings = [];
		foreach ($settings as $key => $value) {
			if (is_string($key)) {
				$key = trim($key);
			}
			if (is_string($value)) {
				$value = trim($value);
			}
			if ($key === '' || $value === '') {
				continue;
			}
			$normalizedSettings[$key] = $value;
		}

		return $normalizedSettings;
	}

	public static function isValidRegularExpression(string $expression): bool {
		return preg_match('~^(?:\(.*\)|\{.*\}|\[.*\])[a-z]*\z~i', $expression) !== 0
			|| preg_match('~^([^a-z\s\\\\]).*\\1[a-z]*\z~i', $expression) !== 0;
	}

	public static function isEnabledByPhpVersion(?bool $value, int $phpVersionLimit): bool {
		if ($value !== null) {
			return $value;
		}

		$phpVersion = Config::getConfigData('php_version') !== null ? (int)Config::getConfigData('php_version') : PHP_VERSION_ID;
		return $phpVersion >= $phpVersionLimit;
	}
}
