<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;
use Nadybot\Core\Attributes as NCA;

#[NCA\Instance]
class Http {
	#[NCA\Inject]
	public Timer $timer;

	/**
	 * Requests contents of given $uri using GET method and returns AsyncHttp
	 * object which has additional methods for controlling how the query is done.
	 *
	 * You can get both HTTP and HTTPS URIs with method.
	 *
	 * For more info, see AsyncHttp class.
	 *
	 * Asynchronous example:
	 * <code>
	 * $this->http->get("http://www.google.com/")->withCallback(function($response) {
	 *     print $response->body;
	 * });
	 * </code>
	 *
	 * Synchronous example:
	 * <code>
	 * $response = $this->http->get("http://www.google.com/")->waitAndReturnResponse();
	 * print $response->body;
	 * </code>
	 */
	public function get(string $uri): AsyncHttp {
		$asyncHttp = new AsyncHttp('get', $uri);
		Registry::injectDependencies($asyncHttp);
		Loop::defer(fn () => $asyncHttp->execute());
		return $asyncHttp;
	}

	/**
	 * Requests contents of given $uri using POST method and returns AsyncHttp
	 * object which has additional methods for controlling how the query is done.
	 *
	 * See get() for code example.
	 */
	public function post(string $uri): AsyncHttp {
		$asyncHttp = new AsyncHttp('post', $uri);
		Registry::injectDependencies($asyncHttp);
		Loop::defer(fn () => $asyncHttp->execute());
		return $asyncHttp;
	}

	/**
	 * Requests a PATCH request to the given $uri and returns AsyncHttp
	 * object which has additional methods for controlling how the query is done.
	 *
	 * See get() for code example.
	 */
	public function patch(string $uri): AsyncHttp {
		$asyncHttp = new AsyncHttp('patch', $uri);
		Registry::injectDependencies($asyncHttp);
		Loop::defer(fn () => $asyncHttp->execute());
		return $asyncHttp;
	}

	/**
	 * Requests a PUT request to the given $uri and returns AsyncHttp
	 * object which has additional methods for controlling how the query is done.
	 *
	 * See get() for code example.
	 */
	public function put(string $uri): AsyncHttp {
		$asyncHttp = new AsyncHttp('put', $uri);
		Registry::injectDependencies($asyncHttp);
		Loop::defer(fn () => $asyncHttp->execute());
		return $asyncHttp;
	}

	/**
	 * Requests a DELETE request to the given $uri and returns AsyncHttp
	 * object which has additional methods for controlling how the query is done.
	 *
	 * See get() for code example.
	 */
	public function delete(string $uri): AsyncHttp {
		$asyncHttp = new AsyncHttp('delete', $uri);
		Registry::injectDependencies($asyncHttp);
		Loop::defer(fn () => $asyncHttp->execute());
		return $asyncHttp;
	}
}
