<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\base;

use Craft;
use craft\app\components\BaseSavableComponentType;
use craft\app\helpers\StringHelper;

/**
 * Plugin base class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
abstract class BasePlugin extends BaseSavableComponentType implements PluginInterface
{
	// Properties
	// =========================================================================

	/**
	 * @var bool
	 */
	public $isInstalled = false;

	/**
	 * @var bool
	 */
	public $isEnabled = false;

	/**
	 * The type of component, e.g. "Plugin", "Widget", "FieldType", etc. Defined by the component type's base class.
	 *
	 * @var string
	 */
	protected $componentType = 'Plugin';

	// Public Methods
	// =========================================================================

	/**
	 * Returns the plugin's source language
	 *
	 * @return string
	 */
	public function getSourceLanguage()
	{
		return Craft::$app->sourceLanguage;
	}

	/**
	 * Returns the URL to the plugin's settings in the CP.
	 *
	 * A full URL is not required -- you can simply return "pluginname/settings".
	 *
	 * If this is left blank, a simple settings page will be provided, filled with whatever getSettingsHtml() returns.
	 *
	 * @return string|null
	 */
	public function getSettingsUrl()
	{
	}

	/**
	 * Returns whether this plugin has its own section in the CP.
	 *
	 * @return bool
	 */
	public function hasCpSection()
	{
		return false;
	}

	/**
	 * Creates any tables defined by the plugin's records.
	 *
	 * @return null
	 */
	public function createTables()
	{
		$records = $this->getRecords('install');

		// Create all tables first
		foreach ($records as $record)
		{
			$record->createTable();
		}

		// Then add the foreign keys
		foreach ($records as $record)
		{
			$record->addForeignKeys();
		}
	}

	/**
	 * Drops any tables defined by the plugin's records.
	 *
	 * @return null
	 */
	public function dropTables()
	{
		$records = $this->getRecords();

		// Drop all foreign keys first
		foreach ($records as $record)
		{
			$record->dropForeignKeys();
		}

		// Then drop the tables
		foreach ($records as $record)
		{
			$record->dropTable();
		}
	}

	/**
	 * Returns the record classes provided by this plugin.
	 *
	 * @param string|null $scenario The scenario to initialize the records with.
	 *
	 * @return array
	 */
	public function getRecords($scenario = null)
	{
		$records = [];
		//$classes = Craft::$app->plugins->getPluginClasses($this, 'records', 'Record', false);

		//foreach ($classes as $class)
		//{
		//	if (Craft::$app->components->validateClass($class))
		//	{
		//		$class = __NAMESPACE__.'\\'.$class;
		//		$records[] = new $class($scenario);
		//	}
		//}

		return $records;
	}

	/**
	 * Perform any actions after the plugin has been installed.
	 *
	 * @return null
	 */
	public function onAfterInstall()
	{
	}

	/**
	 * Perform any actions before the plugin has been installed.
	 *
	 * @return null
	 */
	public function onBeforeInstall()
	{
	}

	/**
	 * Perform any actions before the plugin gets uninstalled.
	 *
	 * @return null
	 */
	public function onBeforeUninstall()
	{
	}
}