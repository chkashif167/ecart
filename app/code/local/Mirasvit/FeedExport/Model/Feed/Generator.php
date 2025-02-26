<?php
/**
 * Mirasvit
 *
 * This source file is subject to the Mirasvit Software License, which is available at http://mirasvit.com/license/.
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to http://www.magentocommerce.com for more information.
 *
 * @category  Mirasvit
 * @package   Advanced Product Feeds
 * @version   1.1.2
 * @build     616
 * @copyright Copyright (C) 2015 Mirasvit (http://mirasvit.com/)
 */


class Mirasvit_FeedExport_Model_Feed_Generator extends Varien_Object
{
    protected $_feed  = null;
    protected $_state = null;

    public function init()
    {
        $this->_feed  = $this->getFeed();
    }

    public function getState()
    {
        if ($this->_state == null) {
            $this->_state = Mage::getModel('feedexport/feed_generator_state')
                ->setKey($this->_feed->getId() . $this->getMode())
                ->load();
        }

        return $this->_state;
    }

    public function process()
    {
        if ($this->getState()->isReady()) {
            $this->getState()->reset();
            $this->getState()->setStatus(Mirasvit_FeedExport_Model_Feed_Generator_State::STATUS_PROCESSING);
            $this->makeChain();

            Mage::helper('feedexport')->addToHistory($this->_feed, Mage::helper('feedexport')->__('Processing'), $this->getState()->__toString());
        }

        foreach ($this->getState()->getChain() as $chainKey => $chainItem) {
            if ($chainItem['status'] == Mirasvit_FeedExport_Model_Feed_Generator_State::CHAIN_STATUS_FINISH) {
                continue;
            }

            try {
                $actionModel = $this->getActionModel($chainItem['action']);
                $actionModel->setData($chainItem)
                    ->setFeed($this->getFeed())
                    ->process();
            } catch (Exception $e) {
                Mage::dispatchEvent('feedexport_generation_fail', array('feed' => $this->_feed, 'error' => $e->getMessage()));
                $this->getState()->setError($e->getMessage())
                    ->setStatus(Mirasvit_FeedExport_Model_Feed_Generator_State::STATUS_ERROR);

                return;
            }

            if ($chainItem['status'] == Mirasvit_FeedExport_Model_Feed_Generator_State::CHAIN_STATUS_READY) {
                Mage::helper('feedexport')->addToHistory($this->_feed, Mage::helper('feedexport')->__('Processing'), $this->getState()->__toString());
            }


            break;
        }
    }

    public function makeChain()
    {
        $this->getState()->addChainItem(array(
            'key'    => 'init',
            'action' => 'init',
            'title'  => Mage::helper('feedexport')->__('Initialization'),
        ));

        if (Mage::app()->getRequest()->getParam('skip') != 'rules') {
            $index = 0;
            foreach ($this->_feed->getRuleIds() as $ruleId) {
                $rule = Mage::getModel('feedexport/rule')->load($ruleId);

                $this->getState()->addChainItem(array(
                    'key'    => 'iterator_rule_'.$ruleId,
                    'action' => 'iterator',
                    'index'  => $index,
                    'type'   => 'rule',
                    'id'     => $ruleId,
                    'title'  => sprintf(Mage::helper('feedexport')->__('Applying filter "%s"', $rule->getName())),
                ));
                $index++;
            }
            $this->getState()->addChainItem(array(
                'key'    => 'mergeRules',
                'action' => 'mergeRules',
                'title'  => Mage::helper('feedexport')->__('Assembling products'),
            ));
        }

        $this->getState()->addChainItem(array(
            'key'    => 'iterator_product',
            'action' => 'iterator',
            'type'   => 'product',
            'title'  => Mage::helper('feedexport')->__('Exporting products'),
        ))->addChainItem(array(
            'key'    => 'iterator_category',
            'action' => 'iterator',
            'type'   => 'category',
            'title'  => Mage::helper('feedexport')->__('Exporting categories'),
        ))->addChainItem(array(
            'key'    => 'iterator_review',
            'action' => 'iterator',
            'type'   => 'review',
            'title'  => Mage::helper('feedexport')->__('Exporting reviews'),
        ))->addChainItem(array(
            'key'    => 'mergeFiles',
            'action' => 'mergeFiles',
            'title'  => Mage::helper('feedexport')->__('Assembling the feed file'),
        ))->addChainItem(array(
            'key'    => 'finish',
            'action' => 'finish',
            'title'  => 'Finalization'
        ));

        return $this;
    }

    public function getActionModel($class)
    {
        return Mage::getModel('feedexport/feed_generator_action_'.$class);
    }
}