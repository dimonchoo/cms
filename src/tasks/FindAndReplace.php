<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\tasks;

use Craft;
use craft\app\base\FieldInterface;
use craft\app\enums\AttributeType;
use craft\app\helpers\ModelHelper;

/**
 * Find and Replace task.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class FindAndReplace extends BaseTask
{
	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_table;

	/**
	 * @var
	 */
	private $_textColumns;

	/**
	 * @var
	 */
	private $_matrixFieldIds;

	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc TaskInterface::getDescription()
	 *
	 * @return string
	 */
	public function getDescription()
	{
		$settings = $this->getSettings();

		return Craft::t('app', 'Replacing “{find}” with “{replace}”', [
			'find'    => $settings->find,
			'replace' => $settings->replace
		]);
	}

	/**
	 * @inheritDoc TaskInterface::getTotalSteps()
	 *
	 * @return int
	 */
	public function getTotalSteps()
	{
		$this->_textColumns = [];
		$this->_matrixFieldIds = [];

		// Is this for a Matrix field?
		$matrixFieldId = $this->getSettings()->matrixFieldId;

		if ($matrixFieldId)
		{
			$matrixField = Craft::$app->fields->getFieldById($matrixFieldId);

			if (!$matrixField || $matrixField->type != 'Matrix')
			{
				return 0;
			}

			$this->_table = Craft::$app->matrix->getContentTableName($matrixField);

			$blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($matrixFieldId);

			foreach ($blockTypes as $blockType)
			{
				$fieldColumnPrefix = 'field_'.$blockType->handle.'_';

				foreach ($blockType->getFields() as $field)
				{
					$this->_checkField($field, $fieldColumnPrefix);
				}
			}
		}
		else
		{
			$this->_table = 'content';

			foreach (Craft::$app->fields->getAllFields() as $field)
			{
				$this->_checkField($field, 'field_');
			}
		}

		return count($this->_textColumns) + count($this->_matrixFieldIds);
	}

	/**
	 * @inheritDoc TaskInterface::runStep()
	 *
	 * @param int $step
	 *
	 * @return bool
	 */
	public function runStep($step)
	{
		$settings = $this->getSettings();

		// If replace is null, there is invalid settings JSON in the database. Guard against it so we don't
		// inadvertently nuke textual content in the database.
		if ($settings->replace !== null)
		{
			if (isset($this->_textColumns[$step]))
			{
				Craft::$app->getDb()->createCommand()->replace($this->_table, $this->_textColumns[$step], $settings->find, $settings->replace)->execute();
				return true;
			}
			else
			{
				$step -= count($this->_textColumns);

				if (isset($this->_matrixFieldIds[$step]))
				{
					$field = Craft::$app->fields->getFieldById($this->_matrixFieldIds[$step]);

					if ($field)
					{
						return $this->runSubTask('FindAndReplace', Craft::t('app', 'Working in Matrix field “{field}”', ['field' => $field->name]), [
							'find'          => $settings->find,
							'replace'       => $settings->replace,
							'matrixFieldId' => $field->id
						]);
					}
					else
					{
						// Oh what the hell.
						return true;
					}
				}
				else
				{
					return false;
				}
			}
		}
		else
		{
			Craft::error('Invalid "replace" in the Find and Replace task probably caused by invalid JSON in the database.', __METHOD__);
			return false;
		}
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc SavableComponent::defineSettings()
	 *
	 * @return array
	 */
	protected function defineSettings()
	{
		return [
			'find'          => AttributeType::String,
			'replace'       => AttributeType::String,
			'matrixFieldId' => AttributeType::String,
		];
	}

	// Private Methods
	// =========================================================================

	/**
	 * Checks whether the given field is saving data into a textual column, and saves it accordingly.
	 *
	 * @param FieldInterface $field
	 * @param string         $fieldColumnPrefix
	 *
	 * @return bool
	 */
	private function _checkField(FieldInterface $field, $fieldColumnPrefix)
	{
		if ($field->type == 'Matrix')
		{
			$this->_matrixFieldIds[] = $field->id;
		}
		else if ($field::hasContentColumn())
		{
			$columnType = $field->getContentColumnType();

			if (preg_match('/^\w+/', $columnType, $matches))
			{
				$columnType = strtolower($matches[0]);

				if (in_array($columnType, ['tinytext', 'mediumtext', 'longtext', 'text', 'varchar', 'string', 'char']))
				{
					$this->_textColumns[] = $fieldColumnPrefix.$field->handle;
				}
			}
		}
	}
}