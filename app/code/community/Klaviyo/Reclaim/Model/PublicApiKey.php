<?php

/**
 * Checkout item
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_Model_PublicApiKey extends Mage_Core_Model_Config_Data
{
  public function save()
  {
    $val = $this->getValue();

    // Make sure the API key is 6-7 characters long.
    if(strlen($val) < 6 || strlen($val) > 7) {
        Mage::throwException('Your Klaviyo public API key should be six characters long. Make sure you\'re not using a private API key.'); 
    }

    return parent::save();
  }
}