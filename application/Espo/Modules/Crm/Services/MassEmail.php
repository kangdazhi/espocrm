<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2015 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 ************************************************************************/

namespace Espo\Modules\Crm\Services;

use \Espo\Core\Exceptions\Forbidden;
use \Espo\Core\Exceptions\Error;

use \Espo\ORM\Entity;

class MassEmail extends \Espo\Services\Record
{
    const MAX_ATTEMPT_COUNT = 3;

    const MAX_PER_HOUR_COUNT = 100;

    private $campaignService = null;

    private $emailTemplateService = null;

    protected function init()
    {
        $this->dependencies[] = 'container';
        $this->dependencies[] = 'language';
    }

    protected function getMailSender()
    {
        return $this->getInjection('container')->get('mailSender');
    }

    protected function getLanguage()
    {
        return $this->getInjection('language');
    }

    protected function beforeCreate(Entity $entity, array $data = array())
    {
        parent::beforeCreate($entity, $data);
        if (!$this->getAcl()->check($entity, 'edit')) {
            throw new Forbidden();
        }
    }

    public function createQueue(Entity $massEmail)
    {
        if ($massEmail->get('status') !== 'Pending') {
            throw new Error("Mass Email '".$massEmail->id."' should be 'Pending'.");
        }
        $existingQueueItemList = $this->getEntityManager()->getRepository('EmailQueueItem')->where(array(
            'status' => ['Pending', 'Failed'],
            'massEmailId' => $massEmail->id
        ))->find();
        foreach ($existingQueueItemList as $existingQueueItem) {
            $this->getEntityManager()->getMapper('RDB')->deleteFromDb('EmailQueueItem', $existingQueueItem->id);
        }

        $targetHash = array();
        $entityList = [];

        $targetListCollection = $massEmail->get('targetLists');
        foreach ($targetListCollection as $targetList) {
            $accountList = $targetList->get('accounts', array(
                'additionalColumnsConditions' => array(
                    'optedOut' => false
                )
            ));
            foreach ($accountList as $account) {
                $hashId = $account->getEntityType() . '-'. $account->id;
                if (!empty($targetHash[$hashId])) {
                    continue;
                }
                $entityList[] = $account;
                $targetHash[$hashId] = true;
            }
            $contactList = $targetList->get('contacts', array(
                'additionalColumnsConditions' => array(
                    'optedOut' => false
                )
            ));
            foreach ($contactList as $contact) {
                $hashId = $contact->getEntityType() . '-'. $contact->id;
                if (!empty($targetHash[$hashId])) {
                    continue;
                }
                $entityList[] = $contact;
                $targetHash[$hashId] = true;
            }
            $leadList = $targetList->get('leads', array(
                'additionalColumnsConditions' => array(
                    'optedOut' => false
                )
            ));
            foreach ($leadList as $lead) {
                $hashId = $lead->getEntityType() . '-'. $lead->id;
                if (!empty($targetHash[$hashId])) {
                    continue;
                }
                $entityList[] = $lead;
                $targetHash[$hashId] = true;
            }
            $userList = $targetList->get('users', array(
                'additionalColumnsConditions' => array(
                    'optedOut' => false
                )
            ));
            foreach ($userList as $user) {
                $hashId = $user->getEntityType() . '-'. $user->id;
                if (!empty($targetHash[$hashId])) {
                    continue;
                }
                $entityList[] = $user;
                $targetHash[$hashId] = true;
            }
        }

        foreach ($entityList as $target) {
            $emailAddress = $target->get('emailAddress');
            if (!$target->get('emailAddress')) continue;
            $emailAddressRecord = $this->getEntityManager()->getRepository('EmailAddress')->getByAddress($emailAddress);
            if ($emailAddressRecord) {
                if ($emailAddressRecord->get('invalid') || $emailAddressRecord->get('optOut')) {
                    continue;
                }
            }

            $queueItem = $this->getEntityManager()->getEntity('EmailQueueItem');
            $queueItem->set(array(
                'massEmailId' => $massEmail->id,
                'status' => 'Pending',
                'targetId' => $target->id,
                'targetType' => $target->getEntityType()
            ));
            $this->getEntityManager()->saveEntity($queueItem);
        }

        $massEmail->set('status', 'In Process');

        if (empty($entityList)) {
            $massEmail->set('status', 'Complete');
        }

        $this->getEntityManager()->saveEntity($massEmail);
    }

    protected function setFailed(Entity $massEmail)
    {
        $massEmail->set('status', 'Failed');
        $this->getEntityManager()->saveEntity($massEmail);

        $queueItemList = $this->getEntityManager()->getRepository('EmailQueueItem')->where(array(
            'status' => 'Pending',
            'massEmailId' => $massEmail->id
        ))->find();
        foreach ($queueItemList as $queueItem) {
            $queueItem->set('status', 'Failed');
            $this->getEntityManager()->saveEntity($queueItem);
        }
    }

    public function processSending(Entity $massEmail)
    {
        $threshold = new \DateTime();
        $threshold->modify('-1 hour');
        $sentLastHourCount = $this->getEntityManager()->getRepository('EmailQueueItem')->where(array(
            'status' => 'Sent',
            'sentAt>' => $threshold->format('Y-m-d H:i:s')
        ))->count();

        $maxBatchSize = $this->getConfig()->get('massEmailMaxPerHourCount', self::MAX_PER_HOUR_COUNT);

        if ($sentLastHourCount >= $maxBatchSize) {
            return;
        }

        $maxBatchSize = $maxBatchSize - $sentLastHourCount;

        $queueItemList = $this->getEntityManager()->getRepository('EmailQueueItem')->where(array(
            'status' => 'Pending',
            'massEmailId' => $massEmail->id
        ))->limit(0, $maxBatchSize)->find();

        $templateId = $massEmail->get('emailTemplateId');
        if (!$templateId) {
            $this->setFailed($massEmail);
            return;
        }

        $campaign = null;
        $campaignId = $massEmail->get('campaignId');
        if ($campaignId) {
            $campaign = $this->getEntityManager()->getEntity('Campaign', $campaignId);
        }
        $emailTemplate = $this->getEntityManager()->getEntity('EmailTemplate', $templateId);
        if (!$emailTemplate) {
            $this->setFailed($massEmail);
            return;
        }
        $attachmetList = $emailTemplate->get('attachmets');

        foreach ($queueItemList as $queueItem) {
            $this->sendQueueItem($queueItem, $massEmail, $emailTemplate, $attachmetList, $campaign);
        }

        $countLeft = $this->getEntityManager()->getRepository('EmailQueueItem')->where(array(
            'status' => 'Pending',
            'massEmailId' => $massEmail->id
        ))->count();
        if ($countLeft == 0) {
            $massEmail->set('status', 'Complete');
            $this->getEntityManager()->saveEntity($massEmail);
        }
    }

    protected function getProparedEmail(Entity $queueItem, Entity $massEmail, Entity $emailTemplate, Entity $target, $trackingUrlList = [])
    {
        $templateParams = array(
            'parent' => $target
        );

        $emailData = $this->getEmailTemplateService()->parseTemplate($emailTemplate, $templateParams);

        $body = $emailData['body'];

        $optOutUrl = $this->getConfig()->get('siteUrl') . '?entryPoint=unsubscribe&id=' . $queueItem->id;
        $optOutLink = '<a href="'.$optOutUrl.'">'.$this->getLanguage()->translate('Unsubscribe', 'labels', 'Campaign').'</a>';

        $body = str_replace('{optOutUrl}', $optOutUrl, $body);
        $body = str_replace('{optOutLink}', $optOutLink, $body);

        foreach ($trackingUrlList as $trackingUrl) {
            $url = $this->getConfig()->get('siteUrl') . '?entryPoint=campaignUrl&id=' . $trackingUrl->id . '&queueItemId=' . $queueItem->id;
            $body = str_replace($trackingUrl->get('urlToUse'), $url, $body);
        }

        if (stripos('?entryPoint=unsubscribe&id', $body) === false) {
            if ($emailData['isHtml']) {
                $body .= "<br><br>" . $optOutLink;
            } else {
                $body .= "\n\n" . $optOutUrl;
            }
        }

        $emailData['body'] = $body;

        $email = $this->getEntityManager()->getEntity('Email');
        $email->set($emailData);

        $emailAddress = $target->get('emailAddress');

        if (empty($emailAddress)) {
            return false;
        }

        $email->set('to', $emailAddress);

        if ($massEmail->get('fromAddress')) {
            $email->set('from', $massEmail->get('fromAddress'));
        }
        if ($massEmail->get('replyToAddress')) {
            $email->set('replyTo', $massEmail->get('replyToAddress'));
        }

        return $email;
    }

    protected function sendQueueItem(Entity $queueItem, Entity $massEmail, Entity $emailTemplate, $attachmetList = [], $campaign = null)
    {
        $target = $this->getEntityManager()->getEntity($queueItem->get('targetType'), $queueItem->get('targetId'));
        if (!$target || !$target->id || !$target->get('emailAddress')) {
            $queueItem->set('status', 'Failed');
            $this->getEntityManager()->saveEntity($queueItem);
            return;
        }

        $emailAddress = $target->get('emailAddress');
        if (!$emailAddress) {
            $queueItem->set('status', 'Failed');
            $this->getEntityManager()->saveEntity($queueItem);

            return false;
        };

        $emailAddressRecord = $this->getEntityManager()->getRepository('EmailAddress')->getByAddress($emailAddress);
        if ($emailAddressRecord) {
            if ($emailAddressRecord->get('invalid') || $emailAddressRecord->get('optOut')) {
                $queueItem->set('status', 'Failed');
                $this->getEntityManager()->saveEntity($queueItem);
                return false;
            }
        }

        $trackingUrlList = [];
        if ($campaign) {
            $trackingUrlList = $campaign->get('trackingUrls');
        }

        $email = $this->getProparedEmail($queueItem, $massEmail, $emailTemplate, $target, $trackingUrlList);

        $params = array();
        if ($massEmail->get('fromName')) {
            $params['fromName'] = $massEmail->get('fromName');
        }
        if ($massEmail->get('replyToName')) {
            $params['replyToName'] = $massEmail->get('replyToName');
        }

        try {
            $attemptCount = $queueItem->get('attemptCount');
            $attemptCount++;
            $queueItem->set('attemptCount', $attemptCount);

            $message = new \Zend\Mail\Message();

            $header = new \Espo\Core\Mail\Mail\Header\XQueueItemId();
            $header->setId($queueItem->id);
            $message->getHeaders()->addHeader($header);

            $this->getMailSender()->useGlobal()->send($email, $params, $message, $attachmetList);

            $emailObject = $emailTemplate;
            if ($massEmail->get('storeSentEmails')) {
                $this->getEntityManager()->saveEntity($email);
                $emailObject = $email;
            }

            $queueItem->set('emailAddress', $target->get('emailAddress'));

            $queueItem->set('status', 'Sent');
            $queueItem->set('sentAt', date('Y-m-d H:i:s'));
            $this->getEntityManager()->saveEntity($queueItem);

            if ($campaign) {
                $this->getCampaignService()->logSent($campaign->id, $queueItem->id, $target, $emailObject, $target->get('emailAddress'));
            }

        } catch (\Exception $e) {
            if ($queueItem->get('attemptCount') >= self::MAX_ATTEMPT_COUNT) {
                $this->setQueueItemFailed($queueItem);
                $queueItem->set('status', 'Failed');
            }
            $this->getEntityManager()->saveEntity($queueItem);
            $GLOBALS['log']->error('MassEmail#sendQueueItem: [' . $e->getCode() . '] ' .$e->getMessage());
            return false;
        }

        return true;
    }

    protected function getEmailTemplateService()
    {
        if (!$this->emailTemplateService) {
            $this->emailTemplateService = $this->getServiceFactory()->create('EmailTemplate');
        }
        return $this->emailTemplateService;
    }

    protected function getCampaignService()
    {
        if (!$this->campaignService) {
            $this->campaignService = $this->getServiceFactory()->create('Campaign');
        }
        return $this->campaignService;
    }

    protected function findLinkedEntitiesQueueItems($id, $params)
    {
        $link = 'queueItems';

        $entity = $this->getEntityManager()->getEntity('MassEmail', $id);

        $selectParams = $this->getSelectManager('EmailQueueItem')->getSelectParams($params, false);

        if (array_key_exists($link, $this->linkSelectParams)) {
            $selectParams = array_merge($selectParams, $this->linkSelectParams[$link]);
        }

        $collection = $this->getRepository()->findRelated($entity, $link, $selectParams);

        $recordService = $this->getRecordService('EmailQueueItem');

        foreach ($collection as $e) {
            $recordService->loadAdditionalFieldsForList($e);
            $recordService->prepareEntityForOutput($e);
        }

        $total = $this->getRepository()->countRelated($entity, $link, $selectParams);

        return array(
            'total' => $total,
            'collection' => $collection
        );

    }
}
