#!/usr/bin/env php
<?php declare(strict_types=1);

use function Amp\File\filesystem;
use Amp\ByteStream\WritableResourceStream;
use Amp\File\FilesystemException;
use Safe\Exceptions\FilesystemException as ExceptionsFilesystemException;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$fs = filesystem();
// $fs = filesystem(new BlockingFilesystemDriver());
// Inspired from https://gist.github.com/vuon9/be16429f751e12f72e220c18777d9bc7
//
// This script will
//   1. Read the file contents provided by STDIN
//   2. Create a temporary file (tries multiple directories)
//   3. Call PHP-CS-Fixer's "fix" command with path to the temp file
//   4. provides the fixed file contents as STDOUT

function error_exit(string $message, int $code=1): never {
	try {
		$stderr = \Amp\ByteStream\getStderr();
		$stderr->write($message . \PHP_EOL);
	} catch (\Throwable) {
	}
	exit($code);
}

// Read file contents from STDIN

$stdin = \Amp\ByteStream\getStdin();
$stdout = \Amp\ByteStream\getStdout();
try {
	$tmpFileRes = \Safe\tmpfile();
} catch (ExceptionsFilesystemException $e) {
	error_exit('Unable to create temporary file: ' . $e->getMessage());
}
$meta = stream_get_meta_data($tmpFileRes);
if (!array_key_exists('uri', $meta)) {
	error_exit('Unable to get name of tempfile.');
}

$tmpFileName = $meta['uri'];
$tmpFile = new WritableResourceStream($tmpFileRes);
try {
	Amp\ByteStream\pipe($stdin, $tmpFile);
} catch (\Exception $e) {
	error_exit('Unable to read from STDIN: ' . $e->getMessage());
}

// Check if PHP-CS-Fixer is installed

$whichBinary = dirname(__DIR__, 2) . '/vendor/bin/php-cs-fixer.phar';

$cmd = sprintf('%s fix --quiet %s', $whichBinary, $tmpFileName);

// Run the command

$process = \Amp\Process\Process::start($cmd);
$output = \Amp\ByteStream\buffer($process->getStdout());
$returnCode = $process->join();

if ($returnCode > 0) {
	error_exit($output, $returnCode);
}

// Return new contents to STDOUT

try {
	$tmpFile = $fs->openFile($tmpFileName, 'r');
	Amp\ByteStream\pipe($tmpFile, $stdout);
} catch (FilesystemException $e) {
	error_exit('Couldn\'t read from temp file after fixing: ' . $e->getMessage());
}

try {
	$fs->deleteFile($tmpFileName);
} catch (FilesystemException $e) {
	error_exit('Couldn\'t delete temp file: ' . $e->getMessage());
}
