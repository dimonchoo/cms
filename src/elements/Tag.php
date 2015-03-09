<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\elements;

use Craft;
use craft\app\base\Element;
use craft\app\base\ElementInterface;
use craft\app\db\Query;
use craft\app\enums\AttributeType;
use craft\app\helpers\DbHelper;
use craft\app\models\ElementCriteria as ElementCriteriaModel;
use craft\app\models\FieldLayout;
use craft\app\models\TagGroup;

/**
 * The Tag class is responsible for implementing and defining tags as a native element type in Craft.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Tag extends Element
{
	// Properties
	// =========================================================================

	/**
	 * @var integer Group ID
	 */
	public $groupId;

	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ElementInterface::hasContent()
	 *
	 * @return bool
	 */
	public static function hasContent()
	{
		return true;
	}

	/**
	 * @inheritDoc ElementInterface::hasTitles()
	 *
	 * @return bool
	 */
	public static function hasTitles()
	{
		return true;
	}

	/**
	 * @inheritDoc ElementInterface::isLocalized()
	 *
	 * @return bool
	 */
	public static function isLocalized()
	{
		return true;
	}

	/**
	 * @inheritDoc ElementInterface::getSources()
	 *
	 * @param string|null $context
	 *
	 * @return array|false
	 */
	public static function getSources($context = null)
	{
		$sources = [];

		foreach (Craft::$app->tags->getAllTagGroups() as $tagGroup)
		{
			$key = 'taggroup:'.$tagGroup->id;

			$sources[$key] = [
				'label'    => Craft::t('app', $tagGroup->name),
				'criteria' => ['groupId' => $tagGroup->id]
			];
		}

		return $sources;
	}

	/**
	 * @inheritDoc ElementInterface::defineTableAttributes()
	 *
	 * @param string|null $source
	 *
	 * @return array
	 */
	public static function defineTableAttributes($source = null)
	{
		return [
			'title' => Craft::t('app', 'Title'),
		];
	}

	/**
	 * @inheritDoc ElementInterface::defineCriteriaAttributes()
	 *
	 * @return array
	 */
	public static function defineCriteriaAttributes()
	{
		return [
			'group'   => AttributeType::Mixed,
			'groupId' => AttributeType::Mixed,
			'order'   => [AttributeType::String, 'default' => 'content.title asc'],

			// TODO: Deprecated
			'name'    => AttributeType::String,
		];
	}

	/**
	 * @inheritDoc ElementInterface::modifyElementsQuery()
	 *
	 * @param Query                $query
	 * @param ElementCriteriaModel $criteria
	 *
	 * @return mixed
	 */
	public static function modifyElementsQuery(Query $query, ElementCriteriaModel $criteria)
	{
		$query
			->addSelect('tags.groupId')
			->innerJoin('{{%tags}} tags', 'tags.id = elements.id');

		if ($criteria->groupId)
		{
			$query->andWhere(DbHelper::parseParam('tags.groupId', $criteria->groupId, $query->params));
		}

		if ($criteria->group)
		{
			$query->innerJoin('{{%taggroups}} taggroups', 'taggroups.id = tags.groupId');
			$query->andWhere(DbHelper::parseParam('taggroups.handle', $criteria->group, $query->params));
		}

		// Backwards compatibility with deprecated params
		// TODO: Remove this code in Craft 4

		if ($criteria->name)
		{
			$query->andWhere(DbHelper::parseParam('content.title', $criteria->name, $query->params));
			Craft::$app->deprecator->log('tag_name_param', 'Tags’ ‘name’ param has been deprecated. Use ‘title’ instead.');
		}

		if (is_string($criteria->order))
		{
			$criteria->order = preg_replace('/\bname\b/', 'title', $criteria->order, -1, $count);

			if ($count)
			{
				Craft::$app->deprecator->log('tag_orderby_name', 'Ordering tags by ‘name’ has been deprecated. Order by ‘title’ instead.');
			}
		}
	}

	/**
	 * @inheritDoc ElementInterface::populateElementModel()
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function populateElementModel($row)
	{
		return Tag::populateModel($row);
	}

	/**
	 * @inheritDoc ElementInterface::getEditorHtml()
	 *
	 * @param ElementInterface $element
	 *
	 * @return string
	 */
	public static function getEditorHtml(ElementInterface $element)
	{
		/** @var Tag $element */
		$html = Craft::$app->templates->renderMacro('_includes/forms', 'textField', [
			[
				'label'     => Craft::t('app', 'Title'),
				'locale'    => $element->locale,
				'id'        => 'title',
				'name'      => 'title',
				'value'     => $element->getContent()->title,
				'errors'    => $element->getErrors('title'),
				'first'     => true,
				'autofocus' => true,
				'required'  => true
			]
		]);

		$html .= parent::getEditorHtml($element);

		return $html;
	}

	/**
	 * @inheritDoc ElementInterface::saveElement()
	 *
	 * @param ElementInterface $element
	 * @param array            $params
	 *
	 * @return bool
	 */
	public static function saveElement(ElementInterface $element, $params)
	{
		/** @var Tag $element */
		return Craft::$app->tags->saveTag($element);
	}

	// Instance Methods
	// -------------------------------------------------------------------------

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		$rules = parent::rules();

		$rules[] = [['groupId'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];

		return $rules;
	}

	/**
	 * @inheritDoc ElementInterface::isEditable()
	 *
	 * @return bool
	 */
	public function isEditable()
	{
		return true;
	}

	/**
	 * @inheritDoc ElementInterface::getFieldLayout()
	 *
	 * @return FieldLayout|null
	 */
	public function getFieldLayout()
	{
		$tagGroup = $this->getGroup();

		if ($tagGroup)
		{
			return $tagGroup->getFieldLayout();
		}
	}

	/**
	 * Returns the tag's group.
	 *
	 * @return TagGroup|null
	 */
	public function getGroup()
	{
		if ($this->groupId)
		{
			return Craft::$app->tags->getTagGroupById($this->groupId);
		}
	}

	// Deprecated Methods
	// -------------------------------------------------------------------------

	/**
	 * Returns the tag's title.
	 *
	 * @deprecated Deprecated in 2.3. Use [[$title]] instead.
	 * @return string
	 *
	 * @todo Remove this method in Craft 4.
	 */
	public function getName()
	{
		Craft::$app->deprecator->log('Tag::name', 'The Tag ‘name’ property has been deprecated. Use ‘title’ instead.');
		return $this->getContent()->title;
	}
}