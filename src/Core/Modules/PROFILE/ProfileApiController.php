<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use function Amp\File\filesystem;

use Amp\File\FilesystemException as AmpFilesystemException;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\ModuleInstance;
use Exception;
use Generator;
use Safe\Exceptions\FilesystemException;
use Throwable;
use Nadybot\Modules\{
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\HttpProtocolWrapper,
	WEBSERVER_MODULE\Request,
	WEBSERVER_MODULE\Response,
};

#[NCA\Instance]
class ProfileApiController extends ModuleInstance {
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
	public function viewProfileEndpoint(Request $request, HttpProtocolWrapper $server, string $profile): Generator {
		$filename = $this->profileController->getFilename($profile);

		if (!@file_exists($filename)) {
			return new Response(Response::NOT_FOUND, [], "Profile {$filename} not found.");
		}
		try {
			$content = yield filesystem()->read($filename);
		} catch (AmpFilesystemException) {
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

		try {
			\Safe\unlink($filename);
		} catch (FilesystemException) {
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
		if (!is_object($request->decodedBody) || !isset($request->decodedBody->op)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$op = $request->decodedBody->op;
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
