<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\assetsourcetypes;

use Craft;
use craft\app\dates\DateTime;
use craft\app\enums\AttributeType;
use craft\app\errors\Exception;
use craft\app\helpers\AssetsHelper;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\StringHelper;
use craft\app\elements\Asset;
use craft\app\models\AssetFolder as AssetFolderModel;
use craft\app\models\AssetOperationResponse as AssetOperationResponseModel;
use craft\app\models\AssetTransformIndex as AssetTransformIndexModel;

Craft::$app->requireEdition(Craft::Pro);

/**
 * The S3 asset source type class. Handles the implementation of Amazon S3 as an asset source type in Craft.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class S3 extends BaseAssetSourceType
{
	// Properties
	// =========================================================================

	/**
	 * A list of predefined endpoints.
	 *
	 * @var array
	 */
	private static $_predefinedEndpoints = [
		'US' => 's3.amazonaws.com',
		'EU' => 's3-eu-west-1.amazonaws.com'
	];

	/**
	 * @var \S3
	 */
	private $_s3;

	// Public Methods
	// =========================================================================

	/**
	 * Get bucket list with credentials.
	 *
	 * @param $keyId
	 * @param $secret
	 *
	 * @throws Exception
	 * @return array
	 */
	public static function getBucketList($keyId, $secret)
	{
		$s3 = new \S3($keyId, $secret);
		$buckets = @$s3->listBuckets();

		if (empty($buckets))
		{
			throw new Exception(Craft::t('app', 'Credentials rejected by target host.'));
		}

		$bucketList = [];

		foreach ($buckets as $bucket)
		{
			$location = $s3->getBucketLocation($bucket);

			$bucketList[] = [
				'bucket' => $bucket,
				'location' => $location,
				'url_prefix' => 'http://'.static::getEndpointByLocation($location).'/'.$bucket.'/'
			];

		}

		return $bucketList;
	}

	/**
	 * Get a bucket's endpoint by location.
	 *
	 * @param $location
	 *
	 * @return string
	 */
	public static function getEndpointByLocation($location)
	{
		if (isset(static::$_predefinedEndpoints[$location]))
		{
			return static::$_predefinedEndpoints[$location];
		}

		return 's3-'.$location.'.amazonaws.com';
	}

	/**
	 * @inheritDoc ComponentTypeInterface::getName()
	 *
	 * @return string
	 */
	public function getName()
	{
		return 'Amazon S3';
	}

	/**
	 * @inheritDoc SavableComponentTypeInterface::getSettingsHtml()
	 *
	 * @return string|null
	 */
	public function getSettingsHtml()
	{
		$settings = $this->getSettings();

		$settings->expires = $this->extractExpiryInformation($settings->expires);

		return Craft::$app->templates->render('_components/assetsourcetypes/S3/settings', [
			'settings' => $settings,
			'periods' => array_merge(['' => ''], $this->getPeriodList())
		]);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::startIndex()
	 *
	 * @param $sessionId
	 *
	 * @return array
	 */
	public function startIndex($sessionId)
	{
		$settings = $this->getSettings();
		$this->_prepareForRequests();

		$offset = 0;
		$total = 0;

		$prefix = $this->_getPathPrefix();
		$fileList = $this->_s3->getBucket($settings->bucket, $prefix);

		$fileList = array_filter($fileList, function($value)
		{
			$path = $value['name'];

			$segments = explode('/', $path);
			// Ignore the file
			array_pop($segments);

			foreach ($segments as $segment)
			{
				if (isset($segment[0]) && $segment[0] == '_')
				{
					return false;
				}
			}

			return true;
		});

		$bucketFolders = [];

		foreach ($fileList as $file)
		{
			// Strip the prefix, so we don't index the parent folders
			$file['name'] = mb_substr($file['name'], StringHelper::length($prefix));

			if (!preg_match(AssetsHelper::INDEX_SKIP_ITEMS_PATTERN, $file['name']))
			{
				// In S3, it's possible to have files in folders that don't exist. E.g. - one/two/three.jpg. If folder
				// "one" is empty, except for folder "two", then "one" won't show up in this list so we work around it.

				// Matches all paths with folders, except if folder is last or no folder at all.
				if (preg_match('/(.*\/).+$/', $file['name'], $matches))
				{
					$folders = explode('/', rtrim($matches[1], '/'));
					$basePath = '';

					foreach ($folders as $folder)
					{
						$basePath .= $folder .'/';

						// This is exactly the case referred to above
						if ( ! isset($bucketFolders[$basePath]))
						{
							$bucketFolders[$basePath] = true;
						}
					}
				}

				if (mb_substr($file['name'], -1) == '/')
				{
					$bucketFolders[$file['name']] = true;
				}
				else
				{
					$indexEntry = [
						'sourceId' => $this->model->id,
						'sessionId' => $sessionId,
						'offset' => $offset++,
						'uri' => $file['name'],
						'size' => $file['size']
					];

					Craft::$app->assetIndexing->storeIndexEntry($indexEntry);
					$total++;
				}
			}
		}

		$indexedFolderIds = [];
		$indexedFolderIds[Craft::$app->assetIndexing->ensureTopFolder($this->model)] = true;

		// Ensure folders are in the DB
		foreach ($bucketFolders as $fullPath => $nothing)
		{
			$folderId = $this->ensureFolderByFullPath($fullPath);
			$indexedFolderIds[$folderId] = true;
		}

		$missingFolders = $this->getMissingFolders($indexedFolderIds);

		return ['sourceId' => $this->model->id, 'total' => $total, 'missingFolders' => $missingFolders];
	}

	/**
	 * @inheritDoc BaseAssetSourceType::processIndex()
	 *
	 * @param $sessionId
	 * @param $offset
	 *
	 * @return mixed
	 */
	public function processIndex($sessionId, $offset)
	{
		$indexEntryModel = Craft::$app->assetIndexing->getIndexEntry($this->model->id, $sessionId, $offset);

		if (empty($indexEntryModel))
		{
			return false;
		}

		$uriPath = $indexEntryModel->uri;
		$fileModel = $this->indexFile($uriPath);
		$this->_prepareForRequests();

		if ($fileModel)
		{
			$settings = $this->getSettings();

			Craft::$app->assetIndexing->updateIndexEntryRecordId($indexEntryModel->id, $fileModel->id);

			$fileModel->size = $indexEntryModel->size;

			$fileInfo = $this->_s3->getObjectInfo($settings->bucket, $this->_getPathPrefix().$uriPath);

			$targetPath = Craft::$app->path->getAssetsImageSourcePath().'/'.$fileModel->id.'.'.IOHelper::getExtension($fileModel->filename);

			$timeModified = new DateTime('@'.$fileInfo['time']);

			if ($fileModel->kind == 'image' && ($fileModel->dateModified != $timeModified || !IOHelper::fileExists($targetPath)))
			{
				$this->_s3->getObject($settings->bucket, $this->_getPathPrefix().$indexEntryModel->uri, $targetPath);
				clearstatcache();
				list ($fileModel->width, $fileModel->height) = getimagesize($targetPath);

				// Store the local source or delete - maxCacheCloudImageSize is king.
				Craft::$app->assetTransforms->storeLocalSource($targetPath, $targetPath);
				Craft::$app->assetTransforms->queueSourceForDeletingIfNecessary($targetPath);
			}

			$fileModel->dateModified = $timeModified;

			Craft::$app->assets->storeFile($fileModel);

			return $fileModel->id;
		}

		return false;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getImageSourcePath()
	 *
	 * @param Asset $file
	 *
	 * @return mixed
	 */
	public function getImageSourcePath(Asset $file)
	{
		return Craft::$app->path->getAssetsImageSourcePath().'/'.$file->id.'.'.IOHelper::getExtension($file->filename);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::putImageTransform()
	 *
	 * @param Asset                    $file
	 * @param AssetTransformIndexModel $index
	 * @param string                   $sourceImage
	 *
	 * @return mixed
	 */
	public function putImageTransform(Asset $file, AssetTransformIndexModel $index, $sourceImage)
	{
		$this->_prepareForRequests();
		$targetFile = $this->_getPathPrefix().$file->getFolder()->path.Craft::$app->assetTransforms->getTransformSubpath($file, $index);

		return $this->putObject($sourceImage, $this->getSettings()->bucket, $targetFile, \S3::ACL_PUBLIC_READ);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::isRemote()
	 *
	 * @return bool
	 */
	public function isRemote()
	{
		return true;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getBaseUrl()
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->getSettings()->urlPrefix.$this->_getPathPrefix();
	}

	/**
	 * Return true if a transform exists at the location for a file.
	 *
	 * @param Asset $file
	 * @param       $location
	 *
	 * @return mixed
	 */
	public function transformExists(Asset $file, $location)
	{
		$this->_prepareForRequests();
		return (bool) @$this->_s3->getObjectInfo($this->getSettings()->bucket, $this->_getPathPrefix().$file->getFolder()->path.$location.'/'.$file->filename);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getLocalCopy()
	 *
	 * @param Asset $file
	 *
	 * @return mixed
	 */

	public function getLocalCopy(Asset $file)
	{
		$location = AssetsHelper::getTempFilePath($file->getExtension());

		$this->_prepareForRequests();
		$this->_s3->getObject($this->getSettings()->bucket, $this->_getS3Path($file), $location);

		return $location;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::folderExists()
	 *
	 * @param AssetFolderModel $parentPath
	 * @param string           $folderName
	 *
	 * @return boolean
	 */
	public function folderExists(AssetFolderModel $parentPath, $folderName)
	{
		$this->_prepareForRequests();
		return (bool) $this->_s3->getObjectInfo($this->getSettings()->bucket, $this->_getPathPrefix().$parentPath.rtrim($folderName, '/').'/');
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseSavableComponentType::defineSettings()
	 *
	 * @return array
	 */
	protected function defineSettings()
	{
		return [
			'keyId'      => [AttributeType::String, 'required' => true],
			'secret'     => [AttributeType::String, 'required' => true],
			'bucket'     => [AttributeType::String, 'required' => true],
			'location'   => [AttributeType::String, 'required' => true],
			'urlPrefix'  => [AttributeType::String, 'required' => true],
			'subfolder'  => [AttributeType::String, 'default' => ''],
			'expires'    => [AttributeType::String, 'default' => ''],
		];
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getNameReplacement()
	 *
	 * @param AssetFolderModel $folder
	 * @param string           $filename
	 *
	 * @return mixed
	 */
	protected function getNameReplacement(AssetFolderModel $folder, $filename)
	{
		$this->_prepareForRequests();
		$fileList = $this->_s3->getBucket($this->getSettings()->bucket, $this->_getPathPrefix().$folder->path);

		$fileList = array_flip(array_map('mb_strtolower', array_keys($fileList)));

		// Double-check
		if (!isset($fileList[mb_strtolower($this->_getPathPrefix().$folder->path.$filename)]))
		{
			return $filename;
		}

		$filenameParts = explode(".", $filename);
		$extension = array_pop($filenameParts);

		$filenameStart = join(".", $filenameParts).'_';
		$index = 1;

		while (isset($fileList[mb_strtolower($this->_getPathPrefix().$folder->path.$filenameStart.$index.'.'.$extension)]))
		{
			$index++;
		}

		return $filenameStart.$index.'.'.$extension;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::insertFileInFolder()
	 *
	 * @param AssetFolderModel $folder
	 * @param string           $filePath
	 * @param string           $filename
	 *
	 * @throws Exception
	 * @return Asset
	 */
	protected function insertFileInFolder(AssetFolderModel $folder, $filePath, $filename)
	{
		$filename = AssetsHelper::cleanAssetName($filename);
		$extension = IOHelper::getExtension($filename);

		if (! IOHelper::isExtensionAllowed($extension))
		{
			throw new Exception(Craft::t('app', 'This file type is not allowed'));
		}

		$uriPath = $this->_getPathPrefix().$folder->path.$filename;

		$this->_prepareForRequests();
		$settings = $this->getSettings();
		$fileInfo = $this->_s3->getObjectInfo($settings->bucket, $uriPath);

		if ($fileInfo)
		{
			$response = new AssetOperationResponseModel();
			return $response->setPrompt($this->getUserPromptOptions($filename))->setDataItem('filename', $filename);
		}

		clearstatcache();
		$this->_prepareForRequests();

		if (!$this->putObject($filePath, $this->getSettings()->bucket, $uriPath, \S3::ACL_PUBLIC_READ))
		{
			throw new Exception(Craft::t('app', 'Could not copy file to target destination'));
		}

		$response = new AssetOperationResponseModel();
		return $response->setSuccess()->setDataItem('filePath', $uriPath);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::deleteSourceFile()
	 *
	 * @param string $subpath
	 *
	 * @return null
	 */
	protected function deleteSourceFile($subpath)
	{
		$this->_prepareForRequests();
		@$this->_s3->deleteObject($this->getSettings()->bucket, $this->_getPathPrefix().$subpath);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::moveSourceFile()
	 *
	 * @param Asset            $file
	 * @param AssetFolderModel $targetFolder
	 * @param string           $filename
	 * @param bool             $overwrite
	 *
	 * @return mixed
	 */
	protected function moveSourceFile(Asset $file, AssetFolderModel $targetFolder, $filename = '', $overwrite = false)
	{
		if (empty($filename))
		{
			$filename = $file->filename;
		}

		$newServerPath = $this->_getPathPrefix().$targetFolder->path.$filename;

		$conflictingRecord = Craft::$app->assets->findFile([
			'folderId' => $targetFolder->id,
			'filename' => $filename
		]);

		$this->_prepareForRequests();
		$settings = $this->getSettings();
		$fileInfo = $this->_s3->getObjectInfo($settings->bucket, $newServerPath);

		$conflict = !$overwrite && ($fileInfo || (!Craft::$app->assets->isMergeInProgress() && is_object($conflictingRecord)));

		if ($conflict)
		{
			$response = new AssetOperationResponseModel();
			return $response->setPrompt($this->getUserPromptOptions($filename))->setDataItem('filename', $filename);
		}


		$bucket = $this->getSettings()->bucket;

		// Just in case we're moving from another bucket with the same access credentials.
		$originatingSourceType = Craft::$app->assetSources->getSourceTypeById($file->sourceId);
		$originatingSettings = $originatingSourceType->getSettings();
		$sourceBucket = $originatingSettings->bucket;

		$this->_prepareForRequests($originatingSettings);

		if (!$this->_s3->copyObject($sourceBucket, $this->_getPathPrefix($originatingSettings).$file->getFolder()->path.$file->filename, $bucket, $newServerPath, \S3::ACL_PUBLIC_READ))
		{
			$response = new AssetOperationResponseModel();
			return $response->setError(Craft::t('app', 'Could not save the file'));
		}

		@$this->_s3->deleteObject($sourceBucket, $this->_getS3Path($file, $originatingSettings));

		if ($file->kind == 'image')
		{
			if ($targetFolder->sourceId == $file->sourceId)
			{
				$transforms = Craft::$app->assetTransforms->getAllCreatedTransformsForFile($file);

				$destination = clone $file;
				$destination->filename = $filename;

				// Move transforms
				foreach ($transforms as $index)
				{
					// For each file, we have to have both the source and destination
					// for both files and transforms, so we can reliably move them
					$destinationIndex = clone $index;

					if (!empty($index->filename))
					{
						$destinationIndex->filename = $filename;
						Craft::$app->assetTransforms->storeTransformIndexData($destinationIndex);
					}

					$from = $file->getFolder()->path.Craft::$app->assetTransforms->getTransformSubpath($file, $index);
					$to   = $targetFolder->path.Craft::$app->assetTransforms->getTransformSubpath($destination, $destinationIndex);

					$this->copySourceFile($from, $to);
					$this->deleteSourceFile($from);
				}
			}
			else
			{
				Craft::$app->assetTransforms->deleteAllTransformData($file);
			}
		}

		$response = new AssetOperationResponseModel();

		return $response->setSuccess()
				->setDataItem('newId', $file->id)
				->setDataItem('newFilename', $filename);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::createSourceFolder()
	 *
	 * @param AssetFolderModel $parentFolder
	 * @param string      $folderName
	 *
	 * @return bool
	 */
	protected function createSourceFolder(AssetFolderModel $parentFolder, $folderName)
	{
		$this->_prepareForRequests();

		return $this->putObject('', $this->getSettings()->bucket, $this->_getPathPrefix().rtrim($parentFolder->path.$folderName, '/').'/', \S3::ACL_PUBLIC_READ);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::renameSourceFolder()
	 *
	 * @param AssetFolderModel $folder
	 * @param string      $newName
	 *
	 * @return bool
	 */
	protected function renameSourceFolder(AssetFolderModel $folder, $newName)
	{
		$newFullPath = $this->_getPathPrefix().IOHelper::getParentFolderPath($folder->path).$newName.'/';

		$this->_prepareForRequests();
		$bucket = $this->getSettings()->bucket;
		$filesToMove = $this->_s3->getBucket($bucket, $this->_getPathPrefix().$folder->path);

		rsort($filesToMove);

		foreach ($filesToMove as $file)
		{
			$filePath = mb_substr($file['name'], StringHelper::length($this->_getPathPrefix().$folder->path));

			$this->_s3->copyObject($bucket, $file['name'], $bucket, $newFullPath.$filePath, \S3::ACL_PUBLIC_READ);
			@$this->_s3->deleteObject($bucket, $file['name']);
		}

		return true;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::deleteSourceFolder()
	 *
	 * @param AssetFolderModel $parentFolder
	 * @param string      $folderName
	 *
	 * @return bool
	 */
	protected function deleteSourceFolder(AssetFolderModel $parentFolder, $folderName)
	{
		$this->_prepareForRequests();
		$bucket = $this->getSettings()->bucket;
		$objectsToDelete = $this->_s3->getBucket($bucket, $this->_getPathPrefix().$parentFolder->path.$folderName);

		foreach ($objectsToDelete as $uri)
		{
			@$this->_s3->deleteObject($bucket, $uri['name']);
		}

		return true;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::canMoveFileFrom()
	 *
	 * @param BaseAssetSourceType $originalSource
	 *
	 * @return mixed
	 */
	protected function canMoveFileFrom(BaseAssetSourceType $originalSource)
	{
		if ($this->model->type == $originalSource->model->type)
		{
			$settings = $originalSource->getSettings();
			$theseSettings = $this->getSettings();

			if ($settings->keyId == $theseSettings->keyId && $settings->secret == $theseSettings->secret)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Put an object into an S3 bucket.
	 *
	 * @param $filePath
	 * @param $bucket
	 * @param $uriPath
	 * @param $permissions
	 *
	 * @return bool
	 */
	protected function putObject($filePath, $bucket, $uriPath, $permissions)
	{
		$object  = empty($filePath) ? '' : ['file' => $filePath];
		$headers = [];

		if (!empty($object) && !empty($this->getSettings()->expires) && DateTimeHelper::isValidIntervalString($this->getSettings()->expires))
		{
			$expires = new DateTime();
			$now = new DateTime();
			$expires->modify('+'.$this->getSettings()->expires);
			$diff = $expires->format('U') - $now->format('U');
			$headers['Cache-Control'] = 'max-age='.$diff.', must-revalidate';
		}

		return $this->_s3->putObject($object, $bucket, $uriPath, $permissions, [], $headers);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::copySourceFile()
	 *
	 * @param $sourceUri
	 * @param $targetUri
	 *
	 * @return bool
	 */
	protected function copySourceFile($sourceUri, $targetUri)
	{
		$bucket = $this->getSettings()->bucket;

		return (bool) @$this->_s3->copyObject($bucket, $sourceUri, $bucket, $targetUri, \S3::ACL_PUBLIC_READ);
	}

	// Private Methods
	// =========================================================================

	/**
	 * Prepare the S3 connection for requests to this bucket.
	 *
	 * @param $settings
	 *
	 * @return null
	 */
	private function _prepareForRequests($settings = null)
	{
		if (is_null($settings))
		{
			$settings = $this->getSettings();
		}

		if (is_null($this->_s3))
		{
			$this->_s3 = new \S3($settings->keyId, $settings->secret);
		}

		\S3::setAuth($settings->keyId, $settings->secret);
		$this->_s3->setEndpoint(static::getEndpointByLocation($settings->location));
	}

	/**
	 * Return a prefix for S3 path for settings.
	 *
	 * @param object|null $settings The settings to use. If null, will use current settings.
	 *
	 * @return string
	 */
	private function _getPathPrefix($settings = null)
	{
		if (is_null($settings))
		{
			$settings = $this->getSettings();
		}

		if (!empty($settings->subfolder))
		{
			return rtrim($settings->subfolder, '/').'/';
		}

		return '';
	}

	/**
	 * Get a file's S3 path.
	 *
	 * @param Asset $file
	 * @param       $settings The source settings to use.
	 *
	 * @return string
	 */
	private function _getS3Path(Asset $file, $settings = null)
	{
		$folder = $file->getFolder();
		return $this->_getPathPrefix($settings).$folder->path.$file->filename;
	}
}