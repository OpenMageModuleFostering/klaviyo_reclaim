<?php
class Klaviyo_Reclaim_Block_Tracking_Default extends Mage_Core_Block_Template
{
    public function isKlaviyoEnabled()
    {
      return Mage::helper('klaviyo_reclaim')->isEnabled() && $this->getKlaviyoAccountId();
    }

    public function getKlaviyoAccountId()
    {
      return Mage::helper('klaviyo_reclaim')->getPublicApiKey();
    }

    public function getCustomer()
    {
      return Mage::getSingleton('customer/session')->getCustomer();
    }
}