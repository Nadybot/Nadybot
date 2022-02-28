<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Modules\WEBSERVER_MODULE\JWT\BeforeValidException;
use Nadybot\Modules\WEBSERVER_MODULE\JWT\ExpiredException;
use Nadybot\Modules\WEBSERVER_MODULE\JWT\SignatureInvalidException;
use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;
use DateTime;
use Exception;
use Safe\Exceptions\DatetimeException;
use stdClass;

/**
 * Based on https://github.com/firebase/php-jwt
 */
class JWT {
	public const ASN1_INTEGER = 0x02;
	public const ASN1_SEQUENCE = 0x10;
	public const ASN1_BIT_STRING = 0x03;

	/**
	 * When checking nbf, iat or expiration times,
	 * we want to provide some extra leeway time to
	 * account for clock skew.
	 */
	public static int $leeway = 0;

	/**
	 * Allow the current timestamp to be specified.
	 * Useful for fixing a value within unit testing.
	 *
	 * Will default to PHP time() value if null.
	 */
	public static ?int $timestamp = null;

	/** @var array<string,string> */
	public static array $supported_algs = [
		'ES384' => 'SHA384',
		'ES256' => 'SHA256',
		'RS256' => 'SHA256',
		'RS384' => 'SHA384',
		'RS512' => 'SHA512',
	];

	/**
	 * Decodes a JWT string into a PHP object.
	 *
	 * @param string[] $allowed_algs
	 * @return stdClass The JWT's payload as a PHP object
	 *
	 * @throws InvalidArgumentException     Provided JWT was empty
	 * @throws UnexpectedValueException     Provided JWT was invalid
	 * @throws SignatureInvalidException    Provided JWT was invalid because the signature verification failed
	 * @throws BeforeValidException         Provided JWT is trying to be used before it's eligible as defined by 'nbf'
	 * @throws BeforeValidException         Provided JWT is trying to be used before it's been created as defined by 'iat'
	 * @throws ExpiredException             Provided JWT has since expired, as defined by the 'exp' claim
	 */
	public static function decode(string $jwt, string $key, array $allowed_algs=[]): stdClass {
		$timestamp = is_null(static::$timestamp) ? time() : static::$timestamp;

		if (empty($key)) {
			throw new InvalidArgumentException('Key may not be empty');
		}
		$tks = explode('.', $jwt);
		if (count($tks) !== 3) {
			throw new UnexpectedValueException('Wrong number of segments');
		}
		[$headb64, $bodyb64, $cryptob64] = $tks;
		$header = static::jsonDecode(static::urlsafeB64Decode($headb64));
		$payload = static::jsonDecode(static::urlsafeB64Decode($bodyb64));
		if (null === ($sig = static::urlsafeB64Decode($cryptob64))) {
			throw new UnexpectedValueException('Invalid signature encoding');
		}
		if (empty($header->alg)) {
			throw new UnexpectedValueException('Empty algorithm');
		}
		if (empty(static::$supported_algs[$header->alg])) {
			throw new UnexpectedValueException('Algorithm not supported');
		}
		if (count($allowed_algs) && !in_array($header->alg, $allowed_algs)) {
			throw new UnexpectedValueException('Algorithm not allowed');
		}
		if ($header->alg === 'ES256' || $header->alg === 'ES384') {
			// OpenSSL expects an ASN.1 DER sequence for ES256/ES384 signatures
			$sig = self::signatureToDER($sig);
		}

/*
		if (is_array($key) || $key instanceof ArrayAccess) {
			if (isset($header->kid)) {
				if (!isset($key[$header->kid])) {
					throw new UnexpectedValueException('"kid" invalid, unable to lookup correct key');
				}
				$key = $key[$header->kid];
			} else {
				throw new UnexpectedValueException('"kid" empty, unable to lookup correct key');
			}
		}
*/
		// Check the signature
		if (!self::verify("$headb64.$bodyb64", $sig, $key, $header->alg)) {
			throw new SignatureInvalidException('Signature verification failed');
		}

		// Check the nbf if it is defined. This is the time that the
		// token can actually be used. If it's not yet that time, abort.
		if (isset($payload->nbf) && $payload->nbf > ($timestamp + static::$leeway)) {
			try {
				$date = \Safe\date(DateTime::ISO8601, $payload->nbf);
			} catch (DatetimeException) {
				$date = "<unknown>";
			}
			throw new BeforeValidException("Cannot handle token prior to {$date}");
		}

		// Check that this token has been created before 'now'. This prevents
		// using tokens that have been created for later use (and haven't
		// correctly used the nbf claim).
		if (isset($payload->iat) && $payload->iat > ($timestamp + static::$leeway)) {
			try {
				$date = \Safe\date(DateTime::ISO8601, $payload->iat);
			} catch (DatetimeException) {
				$date = "<unknown>";
			}
			throw new BeforeValidException("Cannot handle token prior to {$date}");
		}

		// Check if this token has expired.
		if (isset($payload->exp) && ($timestamp - static::$leeway) >= $payload->exp) {
			throw new ExpiredException('Expired token');
		}

		return $payload;
	}

	/**
	 * Verify a signature with the message, key and method. Not all methods
	 * are symmetric, so we must have a separate verify and sign method.
	 *
	 * @param string            $msg        The original message (header and body)
	 * @param string            $signature  The original signature
	 * @param string            $key        For HS*, a string key works. for RS*, must be a resource of an openssl public key
	 * @param string            $alg        The algorithm
	 *
	 * @return bool
	 *
	 * @throws DomainException Invalid Algorithm, bad key, or OpenSSL failure
	 */
	private static function verify(string $msg, string $signature, string $key, string $alg): bool {
		if (empty(static::$supported_algs[$alg])) {
			throw new DomainException('Algorithm not supported');
		}

		$algorithm = static::$supported_algs[$alg];
		$success = \Safe\openssl_verify($msg, $signature, $key, $algorithm);
		if ($success === 1) {
			return true;
		} elseif ($success === 0) {
			return false;
		}
		// returns 1 on success, 0 on failure, -1 on error.
		throw new DomainException(
			'OpenSSL error: ' . openssl_error_string()
		);
	}

	/**
	 * Decode a JSON string into a PHP object.
	 *
	 * @param string $input JSON string
	 *
	 * @return stdClass Object representation of JSON string
	 *
	 * @throws DomainException Provided string was invalid JSON
	 */
	public static function jsonDecode(?string $input): stdClass {
		if (!isset($input)) {
			throw new DomainException("Invalid JSON data received");
		}
		if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
			$obj = \Safe\json_decode($input, false, 512, JSON_BIGINT_AS_STRING);
		} else {
			$max_int_length = strlen((string) PHP_INT_MAX) - 1;
			$json_without_bigints = preg_replace('/:\s*(-?\d{' . $max_int_length . ',})/', ': "$1"', $input);
			$obj = \Safe\json_decode($json_without_bigints);
		}

		if ($errno = json_last_error()) {
			self::handleJsonError($errno);
		} elseif ($obj === null && $input !== 'null') {
			throw new DomainException('Null result with non-null input');
		}
		return $obj;
	}

	/**
	 * Decode a string with URL-safe Base64.
	 *
	 * @param string $input A Base64 encoded string
	 *
	 * @return string A decoded string
	 */
	public static function urlsafeB64Decode($input): ?string {
		$remainder = strlen($input) % 4;
		if ($remainder) {
			$padlen = 4 - $remainder;
			$input .= str_repeat('=', $padlen);
		}
		$decoded = \Safe\base64_decode(strtr($input, '-_', '+/'));
		// @phpstan-ignore-next-line
		if ($decoded === false) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Helper method to create a JSON error.
	 *
	 * @param int $errno An error number from json_last_error()
	 *
	 * @return void
	 */
	private static function handleJsonError($errno): void {
		$messages = [
			JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
			JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
			JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
			JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
			JSON_ERROR_UTF8 => 'Malformed UTF-8 characters' //PHP >= 5.3.3
		];
		throw new DomainException(
			isset($messages[$errno])
				? $messages[$errno]
				: 'Unknown JSON error: ' . $errno
		);
	}

	/**
	 * Convert an ECDSA signature to an ASN.1 DER sequence
	 *
	 * @param   string $sig The ECDSA signature to convert
	 * @return  string The encoded DER object
	 */
	private static function signatureToDER(string $sig): string {
		$chunkSize = (int)(strlen($sig) / 2);
		if ($chunkSize < 1) {
			throw new Exception("Invalid r+s data found");
		}
		// Separate the signature into r-value and s-value
		$rs = str_split($sig, $chunkSize);
		[$r, $s] = $rs;

		// Trim leading zeros
		$r = ltrim($r, "\x00");
		$s = ltrim($s, "\x00");

		// Convert r-value and s-value from unsigned big-endian integers to
		// signed two's complement
		if (ord($r[0]) > 0x7f) {
			$r = "\x00" . $r;
		}
		if (ord($s[0]) > 0x7f) {
			$s = "\x00" . $s;
		}

		return self::encodeDER(
			self::ASN1_SEQUENCE,
			self::encodeDER(self::ASN1_INTEGER, $r) .
				self::encodeDER(self::ASN1_INTEGER, $s)
		);
	}

	/**
	 * Encodes a value into a DER object.
	 *
	 * @param   int     $type DER tag
	 * @param   string  $value the value to encode
	 * @return  string  the encoded object
	 */
	private static function encodeDER(int $type, string $value): string {
		$tag_header = 0;
		if ($type === self::ASN1_SEQUENCE) {
			$tag_header |= 0x20;
		}

		// Type
		$der = chr($tag_header | $type);

		// Length
		$der .= chr(strlen($value));

		return $der . $value;
	}
}
