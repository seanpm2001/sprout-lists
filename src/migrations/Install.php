<?php
/**
 * @link https://sprout.barrelstrengthdesign.com
 * @copyright Copyright (c) Barrel Strength Design LLC
 * @license https://craftcms.github.io/license
 */

namespace barrelstrength\sproutlists\migrations;

use craft\db\Migration;

class Install extends Migration
{
    private $subscribersTable = '{{%sproutlists_subscribers}}';

    private $listsTable = '{{%sproutlists_lists}}';

    private $subscriptionsTable = '{{%sproutlists_subscriptions}}';

    public function safeUp()
    {
        $this->createTable($this->listsTable,
            [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'type' => $this->string(),
                'name' => $this->string(),
                'handle' => $this->string(),
                'count' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid()
            ]
        );

        $this->createTable($this->subscribersTable,
            [
                'id' => $this->primaryKey(),
                'userId' => $this->integer(),
                'email' => $this->string(),
                'firstName' => $this->string(),
                'lastName' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid()
            ]
        );

        $this->createTable($this->subscriptionsTable,
            [
                'id' => $this->primaryKey(),
                'listId' => $this->integer(),
                'itemId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid()
            ]
        );
    }

    public function safeDown()
    {
        $this->dropTable($this->listsTable);
        $this->dropTable($this->subscribersTable);
        $this->dropTable($this->subscriptionsTable);
    }
}