<?php
/**
 * Reclaim Data Helper
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_Helper_Data extends Mage_Core_Helper_Data
{
  /**
   * Path to store config if frontend output is enabled
   * @var string
   */
  const XML_PATH_ENABLED = 'reclaim/general/enabled';

  /**
   * Path to store config where Klaviyo API key is stored
   * @var string
   */
  const XML_PATH_PUBLIC_API_KEY = 'reclaim/general/public_api_key';

  /**
   * Path to store config where Klaviyo API key is stored
   * @var string
   */
  const XML_PATH_PRIVATE_API_KEY = 'reclaim/general/private_api_key';
  
  /**
   * Path to store config for Klaviyo list to sync Magento general subscription list with.
   * @var string
   */
  const XML_PATH_SUBSCRIPTION_LIST = 'reclaim/general/subscription_list';

  /**
   * Path to store config for choosing between displaying Klaviyo list name or Magento default.
   * @var string
   */
  const XML_PATH_USE_KLAVIYO_LIST_NAME = 'reclaim/general/use_klaviyo_list_name';

  /* For the "etc/adminthtml.xml" file when we implement:
  <use_klaviyo_list_name translate="label comment">
      <label>Use Klaviyo List Name</label>
      <frontend_type>select</frontend_type>
      <source_model>adminhtml/system_config_source_yesno</source_model>
      <sort_order>40</sort_order>
      <show_in_default>1</show_in_default>
      <show_in_website>0</show_in_website>
      <show_in_store>1</show_in_store>
      <can_be_empty>1</can_be_empty>
      <comment><![CDATA[Use Klaviyo list name rather than the Magento default, <i>General Subscription</i>.]]></comment>
  </use_klaviyo_list_name>
  */

  /**
   * Utility for fetching settings for our extension.
   * @param integer|string|Mage_Core_Model_Store $store
   * @return mixed
   */
  public function getConfigSetting($setting_key, $store=null)
  {
    $store = is_null($store) ? Mage::app()->getStore() : $store;

    $request_store = Mage::app()->getRequest()->getParam('store');

    // If the request explicitly sets the store, use that.
    if ($request_store && $request_store !== 'undefined') {
      $store = $request_store;
    }

    return Mage::getStoreConfig('reclaim/general/' . $setting_key, $store);
  }


  /**
   * Checks whether the Klaviyo extension is enabled
   * @param integer|string|Mage_Core_Model_Store $store
   * @return boolean
   */
  public function isEnabled($store=null)
  {
    return Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $store);
  }
  
  /**
   * Return the Klaviyo Public API key
   * @param integer|string|Mage_Core_Model_Store $store
   * @return string
   */
  public function getPublicApiKey($store=null)
  {
    return Mage::app()->getWebsite($store)->getConfig(self::XML_PATH_PUBLIC_API_KEY);
  }

  /**
   * Return the Klaviyo Private API key
   * @param integer|string|Mage_Core_Model_Store $store
   * @return string
   */
  public function getPrivateApiKey($store=null)
  {
    return Mage::getStoreConfig(self::XML_PATH_PRIVATE_API_KEY, $store);
  }

  public function getSubscriptionList($store)
  {
    // In case we're switching stores to get the setting.
    $current_store = Mage::app()->getStore();

    Mage::app()->setCurrentStore($store);
    $list_id = $this->getConfigSetting('subscription_list', $store);
    Mage::app()->setCurrentStore($current_store);

    return $list_id;
  }

  /**
   * Returns whether the current user is an admin.
   * @return bool
   */
  public function isAdmin()
  {
    return Mage::getSingleton('admin/session')->isLoggedIn();
  }

  public function getCheckout($quote_id)
  {
    $existing_checkout = Mage::getModel('klaviyo_reclaim/checkout')->getCollection()
      ->addFieldToFilter('quote_id', array('eq' => $quote_id));

    if (!count($existing_checkout)) {
      $checkout = Mage::getModel('klaviyo_reclaim/checkout');
      $checkout->setData(array(
        'checkout_id' => hash('md5', uniqid()),
        'quote_id' => $quote_id,
      ));
      $checkout->save();
    } else {
      $checkout = $existing_checkout->getFirstItem();
    }

    return $checkout;
  }

  public function log($data, $filename)
  {
    if ($this->config('enable_log') != 0) {
      return Mage::getModel('core/log_adapter', $filename)->log($data);
    }
  }
}