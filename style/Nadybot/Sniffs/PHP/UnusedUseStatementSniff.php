<?php

namespace Nadybot\style\Nadybot\Sniffs\PHP;

use PHP_CodeSniffer\{
	Files\File,
	Sniffs\Sniff,
	Util\Tokens,
};

/**
 * Checks for "use" statements that are not needed in a file.
 */
class UnusedUseStatementSniff implements Sniff {
	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {
		return [T_USE];
	}

	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  The position of the current token in the stack passed in $tokens.
	 *
	 * @return void
	 */
	public function process(File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();

		// Only check use statements in the global scope.
		if (empty($tokens[$stackPtr]['conditions']) === false) {
			return;
		}

		// Seek to the end of the statement and get the string before the semi colon.
		$semiColon = $phpcsFile->findEndOfStatement($stackPtr);
		if ($tokens[$semiColon]['code'] !== T_SEMICOLON) {
			return;
		}

		$classPtr = $phpcsFile->findPrevious(
			Tokens::$emptyTokens,
			($semiColon - 1),
			null,
			true
		);

		$search = [];
		if ($tokens[$classPtr]['code'] === T_CLOSE_USE_GROUP) {
			$startSubPtr = $phpcsFile->findPrevious(T_OPEN_USE_GROUP, $classPtr - 1);
			// $baseNamespace = trim($phpcsFile->getTokensAsString(($stackPtr + 1), ($startSubPtr - $stackPtr - 1)));
			$commaPtr = $phpcsFile->findNext(T_COMMA, $startSubPtr + 1);
			while ($commaPtr !== false && $commaPtr < $classPtr) {
				$aliasUsed = $phpcsFile->findPrevious(T_AS, ($commaPtr - 1), $startSubPtr);
				if ($aliasUsed) {
					$classNamePtr = $phpcsFile->findNext(T_STRING, $aliasUsed + 1, $classPtr);
				} else {
					$classNamePtr = $phpcsFile->findPrevious(T_STRING, ($commaPtr - 1), $startSubPtr);
				}
				$search[strtolower($tokens[$classNamePtr]['content'])] = $classNamePtr;
				$startSubPtr = $commaPtr;
				$commaPtr = $phpcsFile->findNext(T_COMMA, $startSubPtr + 1);
			}
		} elseif ($tokens[$classPtr]['code'] !== T_STRING) {
			return;
		} else {
			// Search where the class name is used. PHP treats class names case
			// insensitive, that's why we cannot search for the exact class name string
			// and need to iterate over all T_STRING tokens in the file.
			$lowerClassName = strtolower($tokens[$classPtr]['content']);
			$search[$lowerClassName] = $classPtr;

			// Check if the referenced class is in the same namespace as the current
			// file. If it is then the use statement is not necessary.
			$namespacePtr = $phpcsFile->findPrevious([T_NAMESPACE], $stackPtr);
			// Check if the use statement does aliasing with the "as" keyword. Aliasing
			// is allowed even in the same namespace.
			$aliasUsed = $phpcsFile->findPrevious(T_AS, ($classPtr - 1), $stackPtr);

			if ($namespacePtr !== false && $aliasUsed === false) {
				$nsEnd = $phpcsFile->findNext(
					[T_NS_SEPARATOR, T_STRING, T_WHITESPACE],
					($namespacePtr + 1),
					null,
					true
				);
				$namespace = trim($phpcsFile->getTokensAsString(($namespacePtr + 1), ($nsEnd - $namespacePtr - 1)));

				$useNamespacePtr = $phpcsFile->findNext([T_STRING], ($stackPtr + 1));
				$useNamespaceEnd = $phpcsFile->findNext(
					[T_NS_SEPARATOR, T_STRING],
					($useNamespacePtr + 1),
					null,
					true
				);
				$use_namespace   = rtrim($phpcsFile->getTokensAsString($useNamespacePtr, ($useNamespaceEnd - $useNamespacePtr - 1)), '\\');

				if (strcasecmp($namespace, $use_namespace) === 0) {
					$warning = 'Unnecessary use statement in same namespace';
					$phpcsFile->addWarning($warning, $stackPtr, 'UnusedUse');
					return;
				}
			} //end if
		}
		foreach ($search as $lowerClassName => $position) {
			$this->processClassName($phpcsFile, $lowerClassName, $position);
		}
	} //end process()

	private function processClassName(File $phpcsFile, string $lowerClassName, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();
		$classUsed = $stackPtr+1;
		while ($classUsed !== false) {
			if (in_array(strtolower($tokens[$classUsed]['content']), ['@throws', '@return', '@var', '@param'])) {
				if (
					$tokens[$classUsed + 2]['code'] === T_DOC_COMMENT_STRING
					&& preg_match("/\b" . preg_quote($lowerClassName, "/") . "\b/i", $tokens[$classUsed + 2]['content'])
				) {
					return;
				}
			} elseif (strtolower($tokens[$classUsed]['content']) === $lowerClassName) {
				// If the name is used in a PHP 7 function return type declaration
				// stop.
				if ($tokens[$classUsed]['code'] === T_RETURN_TYPE) {
					return;
				}

				$beforeUsage = $phpcsFile->findPrevious(
					Tokens::$emptyTokens,
					($classUsed - 1),
					null,
					true
				);
				// If a backslash is used before the class name then this is some other
				// use statement.
				if ($tokens[$beforeUsage]['code'] !== T_USE && $tokens[$beforeUsage]['code'] !== T_NS_SEPARATOR) {
					return;
				}

				// Trait use statement within a class.
				if ($tokens[$beforeUsage]['code'] === T_USE && empty($tokens[$beforeUsage]['conditions']) === false) {
					return;
				}
			} //end if

			$classUsed = $phpcsFile->findNext([T_STRING, T_USE, T_RETURN_TYPE, T_DOC_COMMENT_TAG], ($classUsed + 1));
		} //end while

		$warning = 'Unused use statement';
		$phpcsFile->addWarning($warning, $stackPtr, 'UnusedUse');
	}
}//end class
