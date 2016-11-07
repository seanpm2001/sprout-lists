<?php
namespace Craft;

class SproutLists_UserModel extends BaseModel
{
	/**
	 * @access protected
	 * @return array
	 */
	protected function defineAttributes()
	{
		return array(
			'id'          => AttributeType::Number,
			'list'        => AttributeType::Number,
			'userId'      => AttributeType::Number,
			'elementId'   => AttributeType::Number,
			'dateCreated' => AttributeType::DateTime,
			'count'       => AttributeType::Number
		);
	}
}