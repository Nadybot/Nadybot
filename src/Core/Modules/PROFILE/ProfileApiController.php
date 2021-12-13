<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Modules\{
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\HttpProtocolWrapper,
	WEBSERVER_MODULE\Request,
	WEBSERVER_MODULE\Response,
};
use Throwable;

#[NCA\Instance]
class ProfileApiController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public ProfileController $profileController;

	/**
	 * Get a list of saved profiles
	 */
	#[
		NCA\Api("/profile"),
		NCA\GET,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 200, class: "string[]", desc: "A list of saved profiled")
	]
	public function moduleGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		try {
			$profiles = $this->profileController->getProfileList();
		} catch (Throwable $e) {
			return new Response(Response::INTERNAL_SERVER_ERROR);
		}
		return new ApiResponse($profiles);
	}

	/**
	 * View a profile
	 */
	#[
		NCA\Api("/profile/%s"),
		NCA\GET,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 200, class: "string", desc: "Profile found and shown"),
		NCA\ApiResult(code: 404, desc: "Profile not found")
	]
	public function viewProfileEndpoint(Request $request, HttpProtocolWrapper $server, string $profile): Response {
		$filename = $this->profileController->getFilename($profile);

		if (!@file_exists($filename)) {
			return new Response(Response::NOT_FOUND, [], "Profile {$filename} not found.");
		}
		if (($content = file_get_contents($filename)) === false) {
			return new Response(Response::NOT_FOUND, [], "Profile {$filename} not accessible.");
		}
		return new ApiResponse($content);
	}

	/**
	 * Delete a profile
	 */
	#[
		NCA\Api("/profile/%s"),
		NCA\DELETE,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 204, desc: "Profile successfully deleted"),
		NCA\ApiResult(code: 404, desc: "Profile not found")
	]
	public function deleteProfileEndpoint(Request $request, HttpProtocolWrapper $server, string $profile): Response {
		$filename = $this->profileController->getFilename($profile);

		if (!@file_exists($filename)
			|| @unlink($filename) === false) {
			return new Response(Response::NOT_FOUND, [], "Profile {$filename} not found.");
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Load a profile
	 */
	#[
		NCA\Api("/profile/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevelFrom("profile"),
		NCA\RequestBody(class: "Operation", desc: "Must be \"load\"", required: true),
		NCA\ApiResult(code: 204, desc: "Profile load successfully"),
		NCA\ApiResult(code: 402, desc: "Wrong or no operation given"),
		NCA\ApiResult(code: 404, desc: "Profile not found")
	]
	public function loadProfileEndpoint(Request $request, HttpProtocolWrapper $server, string $profile): Response {
		$op = null;
		if (is_object($request->decodedBody)) {
			$op = $request->decodedBody->op ?? null;
		}
		if ($op !== "load") {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$filename = $this->profileController->getFilename($profile);

		if (!@file_exists($filename)) {
			return new Response(Response::NOT_FOUND, [], "Profile {$filename} not found.");
		}
		$output = $this->profileController->loadProfile($filename, $request->authenticatedAs??"_");
		if ($output === null) {
			return new Response(Response::INTERNAL_SERVER_ERROR);
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Load a profile
	 */
	#[
		NCA\Api("/profile/%s"),
		NCA\POST,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 204, desc: "Profile saved successfully"),
		NCA\ApiResult(code: 409, desc: "Profile already exists")
	]
	public function saveProfileEndpoint(Request $request, HttpProtocolWrapper $server, string $profile): Response {
		try {
			$this->profileController->saveProfile($profile);
		} catch (Exception $e) {
			return new Response(Response::CONFLICT, [], "This profile already exists.");
		}
		return new Response(Response::NO_CONTENT);
	}
}
