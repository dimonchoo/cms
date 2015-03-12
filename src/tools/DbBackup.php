<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\tools;

use Craft;
use craft\app\helpers\IOHelper;
use craft\app\io\Zip;

/**
 * Backup Database tool
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class DbBackup extends BaseTool
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ComponentTypeInterface::getName()
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('Backup Database');
	}

	/**
	 * @inheritDoc ToolInterface::getIconValue()
	 *
	 * @return string
	 */
	public function getIconValue()
	{
		return 'database';
	}

	/**
	 * @inheritDoc ToolInterface::getOptionsHtml()
	 *
	 * @return string
	 */
	public function getOptionsHtml()
	{
		return Craft::$app->templates->render('_includes/forms/checkbox', [
			'name'    => 'downloadBackup',
			'label'   => Craft::t('Download backup?'),
			'checked' => true,
		]);
	}

	/**
	 * @inheritDoc ToolInterface::performAction()
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	public function performAction($params = [])
	{
		// In addition to the default tables we want to ignore data in, we also don't care about data in the session
		// table in this tools' case.
		$file = Craft::$app->getDb()->backup(['sessions']);

		if (IOHelper::fileExists($file) && isset($params['downloadBackup']) && (bool)$params['downloadBackup'])
		{
			$destZip = Craft::$app->path->getTempPath().'/'.IOHelper::getFilename($file, false).'.zip';

			if (IOHelper::fileExists($destZip))
			{
				IOHelper::deleteFile($destZip, true);
			}

			IOHelper::createFile($destZip);

			if (Zip::add($destZip, $file, Craft::$app->path->getDbBackupPath()))
			{
				return ['backupFile' => IOHelper::getFilename($destZip, false)];
			}
		}
	}
}