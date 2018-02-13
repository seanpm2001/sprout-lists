<?php

namespace barrelstrength\sproutlists\integrations\sproutlists;

use barrelstrength\sproutbase\contracts\sproutlists\SproutListsBaseListType;
use barrelstrength\sproutlists\elements\Lists;
use barrelstrength\sproutlists\elements\Subscribers;
use barrelstrength\sproutlists\records\Subscription;
use Craft;
use craft\helpers\Template;
use barrelstrength\sproutlists\records\Subscribers as SubscribersRecord;
use barrelstrength\sproutlists\records\Lists as ListsRecord;

class SubscriberListType extends SproutListsBaseListType
{
    /**
     * @return string
     */
    public function getName()
    {
        return Craft::t('sprout-lists', 'Subscriber Lists');
    }

    /**
     * The handle that refers to this list. Used as the 'type' when submitting forms.
     *
     * @return string
     */
    public function getHandle()
    {
        return 'subscriber';
    }

    // Lists
    // =========================================================================

    /**
     * Saves a list.
     *
     * @param Lists $list
     *
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function saveList(Lists $list)
    {
        $list->totalSubscribers = 0;

        return Craft::$app->elements->saveElement($list);
    }

    /**
     * Gets lists.
     *
     * @param null $subscriber
     *
     * @return array
     */
    public function getLists($subscriber = null)
    {
        $lists = [];

        $subscriberRecord = null;

        if ($subscriber != null AND (!empty($subscriber->email) OR !empty($subscriber->userId))) {
            $subscriberAttributes = array_filter([
                'email' => $subscriber->email,
                'userId' => $subscriber->userId
            ]);

            $subscriberRecord = SubscribersRecord::find()->where($subscriberAttributes)->all();
        }
        $listRecords = [];
        if ($subscriberRecord == null) {
            // Only findAll if we are not looking for a specific Subscriber, otherwise we want to return null
            if (empty($subscriber->email)) {
                $listRecords =  ListsRecord::find()->all();
            }
        } else {
            $listRecords = $subscriberRecord->subscriberLists;
        }

        if (!empty($listRecords)) {
            foreach ($listRecords as $listRecord) {
                $list = new Lists();
                $list->setAttributes($listRecord->getAttributes(), false);
                $lists[] = $list;
            }
        }

        return $lists;
    }

    /**
     * @param null $subscriber
     *
     * @return int
     */
    public function getListCount($subscriber = null)
    {
        $lists = $this->getLists($subscriber);

        return count($lists);
    }

    /**
     * Gets list with a given id.
     * @param $listId
     *
     * @return \craft\base\ElementInterface|mixed|null
     */
    public function getListById($listId)
    {
        return Craft::$app->getElements()->getElementById($listId);
    }

    /**
     * Returns an array of all lists that have subscribers.
     *
     * @return array
     */
    public function getListsWithSubscribers()
    {
        $records = SproutLists_SubscriberRecord::model()->with('subscriberLists')->findAll();
        $ids = [];
        $lists = [];

        if ($records) {
            foreach ($records as $record) {
                $ids[] = $record->id;
            }

            $query = craft()->db->createCommand()
                ->select('listId')
                ->where(['in', 'subscriberId', $ids])
                ->from('sproutlists_subscriptions')
                ->group('listId');

            $results = $query->queryAll();

            if (!empty($results)) {
                foreach ($results as $result) {
                    $lists[] = $this->getListById($result['listId']);
                }
            }
        }

        return $lists;
    }

    /**
     * Gets or creates list.
     *
     * @param SproutLists_SubscriptionModel $subscription
     *
     * @return BaseModel|SproutLists_ListModel
     */
    public function getOrCreateList(SproutLists_SubscriptionModel $subscription)
    {
        $listRecord = SproutLists_ListRecord::model()->findByAttributes([
            'handle' => $subscription->listHandle
        ]);

        // If no List exists, dynamically create one
        if ($listRecord) {
            $list = SproutLists_ListModel::populateModel($listRecord);
        } else {
            $list = new SproutLists_ListModel();
            $list->type = 'subscriber';
            $list->elementId = $subscription->elementId;
            $list->name = $subscription->listHandle;
            $list->handle = $subscription->listHandle;

            $this->saveList($list);
        }

        return $list;
    }

    // Subscriptions
    // =========================================================================

    /**
     * @inheritDoc SproutListsBaseListType::subscribe()
     *
     * @param $criteria
     *
     * @return bool
     * @throws \Exception
     */
    public function subscribe($subscription)
    {
        $settings = craft()->plugins->getPlugin('sproutLists')->getSettings();

        $subscriber = new SproutLists_SubscriberModel();

        if (!empty($subscription->email)) {
            $subscriber->email = $subscription->email;
        }

        if (!empty($subscription->userId) && $settings->enableUserSync) {
            $subscriber->userId = $subscription->userId;
        }

        $transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;

        try {
            // If our List doesn't exist, create a List Element on the fly
            $list = $this->getOrCreateList($subscription);

            // If it didn't work, rollback the transaction. Can't save a subscription without a List.
            if (!$list->id) {
                if ($transaction !== null) {
                    $transaction->rollback();
                }

                return false;
            }

            // If our Subscriber doesn't exist, create a Subscriber Element on the fly
            $subscriber = $this->getSubscriber($subscriber);

            // If it didn't work, rollback the transaction. Can't save a subscription without a Subscriber.
            if (!$subscriber->id) {
                if ($transaction !== null) {
                    $transaction->rollback();
                }

                return false;
            }

            $subscriptionRecord = new SproutLists_SubscriptionRecord();
            $subscriptionRecord->listId = $list->id;
            $subscriptionRecord->subscriberId = $subscriber->id;

            // Create a criteria between our List Element and Subscriber Element
            if ($subscriptionRecord->save(false)) {
                $this->updateTotalSubscribersCount($subscriptionRecord->listId);
            }

            // Commit the transaction regardless of whether we saved the entry, in case something changed
            // in onBeforeSaveEntry
            if ($transaction !== null) {
                $transaction->commit();
            }

            return true;
        } catch (\Exception $e) {
            if ($transaction && $transaction->active) {
                $transaction->rollback();
            }

            throw $e;
        }
    }

    /**
     * @inheritDoc SproutListsBaseListType::unsubscribe()
     *
     * @param $subscription
     *
     * @return bool
     */
    public function unsubscribe($subscription)
    {
        $settings = craft()->plugins->getPlugin('sproutLists')->getSettings();

        if ($subscription->id) {
            $list = SproutLists_ListRecord::model()->findById($subscription->id);
        } else {
            $list = SproutLists_ListRecord::model()->findByAttributes([
                'type' => $subscription->listType,
                'handle' => $subscription->listHandle
            ]);
        }

        if (!$list) {
            return false;
        }

        // Determine the subscriber that we will un-subscribe
        $subscriberRecord = new SproutLists_SubscriberRecord();

        if (!empty($subscription->userId) && $settings->enableUserSync) {
            $subscriberRecord = SproutLists_SubscriberRecord::model()->findByAttributes([
                'userId' => $subscription->userId
            ]);
        } elseif (!empty($subscription->email)) {
            $subscriberRecord = SproutLists_SubscriberRecord::model()->findByAttributes([
                'email' => $subscription->email
            ]);
        }

        if (!isset($subscriberRecord->id)) {
            return false;
        }

        // Delete the subscription that matches the List and Subscriber IDs
        $subscriptions = SproutLists_SubscriptionRecord::model()->deleteAllByAttributes([
            'listId' => $list->id,
            'subscriberId' => $subscriberRecord->id
        ]);

        if ($subscriptions != null) {
            $this->updateTotalSubscribersCount();

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc SproutListsBaseListType::isSubscribed()
     *
     * @param $criteria
     *
     * @return bool
     */
    public function isSubscribed($subscription)
    {
        $settings = craft()->plugins->getPlugin('sproutLists')->getSettings();

        if (empty($subscription->listHandle)) {
            throw new Exception(Craft::t('Missing argument: `listHandle` is required by the isSubscribed variable'));
        }

        // We need a user ID or an email, however, if User Sync is not enabled, we need an email
        if ((empty($subscription->userId) && empty($subscription->email)) OR
            ($settings->enableUserSync == false) && empty($subscription->email)
        ) {
            throw new Exception(Craft::t('Missing argument: `userId` or `email` are required by the isSubscribed variable'));
        }

        $listId = null;
        $subscriberId = null;

        $listRecord = SproutLists_ListRecord::model()->findByAttributes([
            'handle' => $subscription->listHandle
        ]);

        if ($listRecord) {
            $listId = $listRecord->id;
        }

        $attributes = array_filter([
            'email' => $subscription->email,
            'userId' => $subscription->userId
        ]);

        $subscriberRecord = SproutLists_SubscriberRecord::model()->findByAttributes($attributes);

        if ($subscriberRecord) {
            $subscriberId = $subscriberRecord->id;
        }

        if ($listId != null && $subscriberId != null) {
            $subscriptionRecord = SproutLists_SubscriptionRecord::model()->findByAttributes([
                'subscriberId' => $subscriberId,
                'listId' => $listId
            ]);

            if ($subscriptionRecord) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saves a subscribers subscriptions.
     * @param Subscribers $subscriber
     *
     * @return bool
     * @throws \Exception
     */
    public function saveSubscriptions(Subscribers $subscriber)
    {
        try {
            Subscription::deleteAll('subscriberId = :subscriberId', [
                ':subscriberId' => $subscriber->id
            ]);

            if (!empty($subscriber->subscriberLists)) {
                foreach ($subscriber->subscriberLists as $listId) {
                    $list = $this->getListById($listId);

                    if ($list) {
                        $subscriptionRecord = new Subscription();
                        $subscriptionRecord->subscriberId = $subscriber->id;
                        $subscriptionRecord->listId = $list->id;

                        if (!$subscriptionRecord->save(false)) {
                            throw new \Exception(print_r($subscriptionRecord->getErrors(), true));
                        }
                    } else {
                        throw new \Exception(Craft::t('The Subscriber List with id {listId} does not exists.',$listId));
                    }
                }
            }

            $this->updateTotalSubscribersCount();

            return true;
        } catch (\Exception $e) {
            Craft::error($e->getMessage());
            throw $e;
        }
    }

    // Subscribers
    // =========================================================================

    /**
     * @param Subscribers $subscriber
     *
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function saveSubscriber(Subscribers $subscriber)
    {
       if (Craft::$app->getElements()->saveElement($subscriber)) {
           $this->saveSubscriptions($subscriber);
       }

       return true;
    }

    /**
     * @inheritDoc SproutListsBaseListType::getSubscribers()
     *
     * @param $list
     *
     * @return array|mixed
     */
    public function getSubscribers($list)
    {
        if (empty($list->type)) {
            throw new Exception(Craft::t("Missing argument: 'type' is required by the getSubscribers variable."));
        }

        if (empty($list->handle)) {
            throw new Exception(Craft::t("Missing argument: 'listHandle' is required by the getSubscribers variable."));
        }

        $subscribers = [];

        if (empty($list)) {
            return $subscribers;
        }

        $listRecord = SproutLists_ListRecord::model()->findByAttributes([
            'type' => $list->type,
            'handle' => $list->handle
        ]);

        if ($listRecord != null) {
            $subscribers = SproutLists_SubscriberModel::populateModels($listRecord->subscribers);

            return $subscribers;
        }

        return $subscribers;
    }

    public function getSubscriberCount($list)
    {
        $subscribers = $this->getSubscribers($list)->all();

        return count($subscribers);
    }

    /**
     * Gets a subscriber.
     *
     * @param SproutLists_SubscriberModel $subscriber
     *
     * @return BaseModel|SproutLists_SubscriberModel
     */
    public function getSubscriber(SproutLists_SubscriberModel $subscriber)
    {
        $attributes = array_filter([
            'email' => $subscriber->email,
            'userId' => $subscriber->userId
        ]);

        $subscriberRecord = SproutLists_SubscriberRecord::model()->findByAttributes($attributes);

        if (!empty($subscriberRecord)) {
            $subscriber = SproutLists_SubscriberModel::populateModel($subscriberRecord);
        }

        // If no Subscriber was found, create one
        if (!$subscriber->id) {
            if (isset($subscriber->userId)) {
                $user = craft()->users->getUserById($subscriber->userId);

                if ($user) {
                    $subscriber->email = $user->email;
                }
            }

            $this->saveSubscriber($subscriber);
        }

        return $subscriber;
    }

    /**
     * Gets a subscriber with a given id.
     * @param $id
     *
     * @return \craft\base\ElementInterface|null
     */
    public function getSubscriberById($id)
    {
        return Craft::$app->getElements()->getElementById($id);
    }

    /**
     * Deletes a subscriber.
     *
     * @param $id
     *
     * @return BaseModel|SproutLists_SubscriberModel
     */
    public function deleteSubscriberById($id)
    {
        $subscriber = $this->getSubscriberById($id);

        if ($subscriber->id != null) {
            if (craft()->elements->deleteElementById($subscriber->id)) {
                SproutLists_SubscriptionRecord::model()->deleteAll('subscriberId = :subscriberId', [':subscriberId' => $subscriber->id]);
            }
        }

        $this->updateTotalSubscribersCount();

        return $subscriber;
    }

    /**
     * Gets the HTML output for the lists sidebar on the Subscriber edit page.
     * @param $subscriberId
     *
     * @return \Twig_Markup
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     */
    /**
     * @param $subscriberId
     *
     * @return \Twig_Markup
     * @throws \Exception
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     */
    public function getSubscriberListsHtml($subscriberId)
    {
        $default = [];
        $listIds = [];

        if ($subscriberId != null) {

            $subscriber = $this->getSubscriberById($subscriberId);

            if ($subscriber) {
                /**
                 * @var $subscriber Subscribers
                 */
                $listIds = $subscriber->getListIds();
            }
        }

        $lists = $this->getLists();

        $options = [];

        if (count($lists)) {
            foreach ($lists as $list) {
                $options[] = [
                    'label' => sprintf('%s', $list->name),
                    'value' => $list->id
                ];
            }
        }

        if (!empty($default)) {
            $listIds = $default;
        }

        $html = Craft::$app->getView()->renderTemplate('sprout-lists/subscribers/_subscriptionlists', [
            'options' => $options,
            'values' => $listIds
        ]);

        return Template::raw($html);
    }

    /**
     * Updates the totalSubscribers column in the db
     *
     * @param null $listId
     *
     * @return bool
     */
    public function updateTotalSubscribersCount($listId = null)
    {
        if ($listId == null) {
            $lists = ListsRecord::find()->all();
        } else {
            $list = ListsRecord::findOne($listId);

            $lists = [$list];
        }

        if (count($lists)) {
            foreach ($lists as $list) {

                if (!$list) continue;

                $count = count($list->getSubscribers()->all());

                $list->totalSubscribers = $count;

                $list->save();
            }

            return true;
        }

        return false;
    }
}