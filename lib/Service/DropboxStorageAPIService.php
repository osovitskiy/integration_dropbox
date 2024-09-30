<?php
/**
 * Nextcloud - dropbox
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Dropbox\Service;

use DateTime;
use Exception;
use OCA\Dropbox\AppInfo\Application;
use OCA\Dropbox\BackgroundJob\ImportDropboxJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

class DropboxStorageAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IJobList
	 */
	private $jobList;
	/**
	 * @var DropboxAPIService
	 */
	private $dropboxApiService;
	/**
	 * @var UserScopeService
	 */
	private $userScopeService;

	/**
	 * Service to make requests to Dropbox API
	 */
	public function __construct(string $appName,
		LoggerInterface $logger,
		IRootFolder $root,
		IConfig $config,
		IJobList $jobList,
		UserScopeService $userScopeService,
		DropboxAPIService $dropboxApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->root = $root;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->dropboxApiService = $dropboxApiService;
		$this->userScopeService = $userScopeService;
	}

	/**
	 * Return dropbox storage size
	 *
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @return array{usageInStorage?: mixed, error?: string}
	 */
	public function getStorageSize(string $accessToken, string $refreshToken, string $clientID, string $clientSecret, string $userId): array {
		$params = [];
		$result = $this->dropboxApiService->request($accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'users/get_space_usage', $params, 'POST');
		if (isset($result['error']) || !isset($result['used'])) {
			return $result;
		}
		return [
			'usageInStorage' => $result['used'],
		];
	}

	/**
	 * @param string $userId
	 * @return array{error?: string, targetPath?: string}
	 */
	public function startImportDropbox(string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'output_dir', '/Dropbox import');
		$targetPath = $targetPath ?: '/Dropbox import';
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if (!$folder instanceof Folder) {
				return ['error' => 'Impossible to create Dropbox folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_dropbox', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0');

		$this->jobList->add(ImportDropboxJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function importDropboxJob(string $userId): void {
		$this->logger->error('Importing dropbox files for ' . $userId);

		// in case SSE is enabled
		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingDropbox = $this->config->getUserValue($userId, Application::APP_ID, 'importing_dropbox', '0') === '1';
		if (!$importingDropbox) {
			return;
		}
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0') === '1';
		$nowTs = (new DateTime())->getTimestamp();
		if ($jobRunning) {
			$lastJobStart = $this->config->getUserValue($userId, Application::APP_ID, 'dropbox_import_job_last_start');
			if ($lastJobStart !== '' && ($nowTs - intval($lastJobStart) < Application::IMPORT_JOB_TIMEOUT)) {
				// last job has started less than an hour ago => we consider it can still be running
				return;
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'last_import_error', '');
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_job_last_start', strval($nowTs));

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'output_dir', '/Dropbox import');
		$targetPath = $targetPath ?: '/Dropbox import';

		try {
			$targetNode = $this->root->getUserFolder($userId)->get($targetPath);
			if ($targetNode->isShared()) {
				$this->logger->error('Target path ' . $targetPath . 'is shared, resorting to user root folder');
				$targetPath = '/';
			}
		} catch (NotFoundException) {
			// noop, folder doesn't exist
		} catch (NotPermittedException) {
			$this->logger->error('Cannot determine if target path ' . $targetPath . 'is shared, resorting to root folder');
			$targetPath = '/';
		}

		// import by batch of 500 Mo
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImported = (int)$alreadyImported;
		try {
			$result = $this->importFiles($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $targetPath, 500000000, $alreadyImported);
		} catch (Exception|Throwable $e) {
			$result = [
				'error' => 'Unknow job failure. ' . $e->getMessage(),
			];
		}
		if (isset($result['finished']) && $result['finished']) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_dropbox', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0');
			$this->dropboxApiService->sendNCNotification($userId, 'import_dropbox_finished', [
				'nbImported' => $result['totalSeen'],
				'targetPath' => $targetPath,
			]);
		}
		if (isset($result['error'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'last_import_error', $result['error']);
		}
		if ((!isset($result['finished']) || !$result['finished']) && !isset($result['error'])) {
			$ts = (string)(new DateTime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', $ts);
			$this->jobList->add(ImportDropboxJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0');
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array{nbDownloaded: int, targetPath: string, finished: bool, totalSeen: int}|array{error: non-empty-string}
	 */
	public function importFiles(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
		string $userId, string $targetPath,
		?int $maxDownloadSize = null, int $alreadyImported = 0): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if (!$folder instanceof Folder) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;
		$knownFolders = array('' => $folder, '.' => $folder);

		$params = [
			'limit' => 2000,
			'path' => '',
			'recursive' => true,
			'include_deleted' => false,
			'include_has_explicit_shared_members' => false,
			'include_mounted_folders' => true,
			'include_non_downloadable_files' => false,
		];
		do {
			$suffix = isset($params['cursor']) ? '/continue' : '';
			$result = $this->dropboxApiService->request(
				$accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'files/list_folder' . $suffix, $params, 'POST'
			);
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['entries']) && is_array($result['entries'])) {
				foreach ($result['entries'] as $entry) {
					if (isset($entry['.tag']) && $entry['.tag'] === 'folder') {
						$this->createFolder($entry, $knownFolders);
					}
					if (isset($entry['.tag']) && $entry['.tag'] === 'file') {
						$totalSeenNumber++;
						$size = $this->getFile($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $entry, $knownFolders);
						if (!is_null($size)) {
							$nbDownloaded++;
							$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', (string)($alreadyImported + $nbDownloaded));
							$downloadedSize += $size;
							if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
								return [
									'nbDownloaded' => $nbDownloaded,
									'targetPath' => $targetPath,
									'finished' => false,
									'totalSeen' => $totalSeenNumber,
								];
							}
						}
					}
				}
			}
			$params = [
				'cursor' => $result['cursor'] ?? '',
			];
		} while (isset($result['has_more'], $result['cursor']) && $result['has_more']);

		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeen' => $totalSeenNumber,
		];
	}

	/**
	 * @param array $folderItem
	 * @param array $knownFolders
	 * @return void
	 */
	private function createFolder(array $folderItem, array &$knownFolders): void {
		$folderName = $folderItem['name'];
		$path = preg_replace('/^\//', '', $folderItem['path_lower'] ?? '.');
		$pathParts = pathinfo($path);
		$dirName = $pathParts['dirname'];
		if (!isset($knownFolders[$dirName])) {
			$this->logger->warning(
				'Dropbox error, unrecognized parent folder "' . $dirName . '"',
				['app' => $this->appName]
			);
			return;
		}
		$parentFolder = $knownFolders[$dirName];
		if ($parentFolder->nodeExists($folderName)) {
			$folderNode = $parentFolder->get($folderName);
			if ($folderNode->getType() === FileInfo::TYPE_FOLDER) {
				$knownFolders[$path] = $folderNode;
			} else {
				try {
					$folderNode->delete();
				} catch (NotPermittedException $e) {
					$this->logger->warning(
						'Dropbox error, can\'t delete obsolete file "' . $path . '"',
						['app' => $this->appName]
					);
					return;
				}
				$knownFolders[$path] = $parentFolder->newFolder($folderName);
			}
		} else {
			$knownFolders[$path] = $parentFolder->newFolder($folderName);
		}
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param array{size: float, path_display: string, name: string, id: string, server_modified: string} $fileItem
	 * @param Folder $topFolder
	 * @return ?float downloaded size, null if already existing or network error
	 */
	private function getFile(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
		string $userId, array $fileItem, array &$knownFolders): ?float {
		$fileName = $fileItem['name'];
		$path = preg_replace('/^\//', '', $fileItem['path_lower'] ?? '.');
		$pathParts = pathinfo($path);
		$dirName = $pathParts['dirname'];
		if (!isset($knownFolders[$dirName])) {
			$this->logger->warning(
				'Dropbox error, unrecognized parent folder "' . $dirName . '"',
				['app' => $this->appName]
			);
			return null;
		}
		$ts = null;
		if (isset($fileItem['server_modified'])) {
			$d = new Datetime($fileItem['server_modified']);
			$ts = $d->getTimestamp();
		}
		$saveFolder = $knownFolders[$dirName];
		if ($saveFolder->nodeExists($fileName)) {
			$fileNode = $saveFolder->get($fileName);
			if ($fileNode->getType() === FileInfo::TYPE_FILE) {
				if ($ts !== null && $ts > $fileNode->getMTime()) {
					$savedFile = $fileNode;
				} else {
					return null;
				}
			} else {
				try {
					$fileNode->delete();
				} catch (NotPermittedException $e) {
					$this->logger->warning(
						'Dropbox error, can\'t delete obsolete folder "' . $path . '"',
						['app' => $this->appName]
					);
					return null;
				}
			}
		} else {
			try {
				$savedFile = $saveFolder->newFile($fileName);
			} catch (NotFoundException $e) {
				$this->logger->warning(
					'Dropbox error, can\'t create file "' . $fileName . '" in "' . $saveFolder->getPath() . '"',
					['app' => $this->appName]
				);
				return null;
			}
		}
		try {
			$resource = $savedFile->fopen('w');
		} catch (NotFoundException|NotPermittedException|LockedException $e) {
			$this->logger->warning(
				'Dropbox error, can\'t open file for writing "' . $path . '"',
				['app' => $this->appName]
			);
			return null;
		}
		if ($resource === false) {
			$this->logger->warning(
				'Dropbox error, can\'t open file "' . $fileName . '" in "' . $saveFolder->getPath() . '"',
				['app' => $this->appName]
			);
			return null;
		}
		$res = $this->dropboxApiService->downloadFile(
			$accessToken, $refreshToken, $clientID, $clientSecret, $userId, $resource, $fileItem['id']
		);
		if (isset($res['error'])) {
			$this->logger->warning('Dropbox error downloading file ' . $fileName . ' : ' . $res['error'], ['app' => $this->appName]);
			try {
				if ($savedFile->isDeletable()) {
					$savedFile->delete();
				}
			} catch (LockedException $e) {
				$this->logger->warning('Dropbox error deleting file ' . $fileName, ['app' => $this->appName]);
			}
			return null;
		}
		fclose($resource);
		if ($ts !== null) {
			$savedFile->touch($ts);
		} else {
			$savedFile->touch();
		}
		return $fileItem['size'];
	}
}
