<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\records;

use Craft;
use craft\app\db\ActiveRecord;
use craft\app\enums\AttributeType;
use craft\app\enums\ColumnType;

/**
 * Class Field record.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Field extends ActiveRecord
{
	// Properties
	// =========================================================================

	/**
	 * @var array
	 */
	protected $reservedHandleWords = [
		'archived',
		'children',
		'dateCreated',
		'dateUpdated',
		'enabled',
		'id',
		'link',
		'locale',
		'parents',
		'siblings',
		'uid',
		'uri',
		'url',
		'ref',
		'status',
		'title',
	];

	/**
	 * @var
	 */
	private $_oldHandle;

	// Public Methods
	// =========================================================================

	/**
	 * Initializes the application component.
	 *
	 * @return null
	 */
	public function init()
	{
		parent::init();

		// Store the old handle in case it's ever requested.
		//$this->attachEventHandler('onAfterFind', [$this, 'storeOldHandle']);
	}

	/**
	 * Store the old handle.
	 *
	 * @return null
	 */
	public function storeOldHandle()
	{
		$this->_oldHandle = $this->handle;
	}

	/**
	 * Returns the old handle.
	 *
	 * @return string
	 */
	public function getOldHandle()
	{
		return $this->_oldHandle;
	}

	/**
	 * @inheritdoc
	 *
	 * @return string
	 */
	public static function tableName()
	{
		return '{{%fields}}';
	}

	/**
	 * Returns the field’s group.
	 *
	 * @return \yii\db\ActiveQueryInterface The relational query object.
	 */
	public function getGroup()
	{
		return $this->hasOne(FieldGroup::className(), ['id' => 'groupId']);
	}

	/**
	 * @inheritDoc ActiveRecord::defineIndexes()
	 *
	 * @return array
	 */
	public function defineIndexes()
	{
		return [
			['columns' => ['handle', 'context'], 'unique' => true],
			['columns' => ['context']],
		];
	}

	/**
	 * Set the max field handle length based on the current field column prefix length.
	 *
	 * @return array
	 */
	public function getAttributeConfigs()
	{
		$attributeConfigs = parent::getAttributeConfigs();

		// TODO: MySQL specific.
		// Field handles must be <= 58 chars so that with "field_" prepended, they're <= 64 chars (MySQL's column
		// name limit).
		$attributeConfigs['handle']['maxLength'] = 64 - strlen(Craft::$app->content->fieldColumnPrefix);

		return $attributeConfigs;
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc ActiveRecord::defineAttributes()
	 *
	 * @return array
	 */
	protected function defineAttributes()
	{
		return [
			'name'         => [AttributeType::Name, 'required' => true],
			'handle'       => [AttributeType::Handle, 'required' => true, 'reservedWords' => $this->reservedHandleWords],
			'context'      => [AttributeType::String, 'default' => 'global', 'required' => true],
			'instructions' => [AttributeType::String, 'column' => ColumnType::Text],
			'translatable' => AttributeType::Bool,
			'type'         => [AttributeType::ClassName, 'required' => true],
			'settings'     => AttributeType::Mixed,
		];
	}
}