<?php

/**
 * Reclaim frontend controller
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_IndexController extends Mage_Core_Controller_Front_Action
{

  private static $_preservableRequestParams = array('utm_medium', 'utm_source', 'utm_campaign', 'utm_term');

  /**
   * Pre dispatch action that allows to redirect to no route page in case of disabled extension through Admin panel
   */
  public function preDispatch()
  {
    parent::preDispatch();

    if (!Mage::helper('klaviyo_reclaim')->isEnabled()) {
      $this->setFlag('', 'no-dispatch', true);
      $this->_redirect('noRoute');
    }
  }

  /**
   * Checkout item action
   */
  public function viewAction()
  {
    $request = $this->getRequest();
    $checkout_id = $request->getParam('id');

    if ($checkout_id) {
      $checkout = Mage::getModel('klaviyo_reclaim/checkout');
      $checkout->load($checkout_id);

      if ($checkout->getId()) {
        $saved_quote = Mage::getModel('sales/quote');
        $saved_quote->load($checkout->getQuoteId());
        $cart = Mage::getSingleton('checkout/cart');

        if ($saved_quote->getId() != $cart->getQuote()->getId() && !$cart->getItemsCount()) {
          $cart->getQuote()->load($checkout->getQuoteId());
          $cart->save();
        }
      }
    }

    $params = array();
    foreach (self::$_preservableRequestParams as $key) {
      $value = $this->getRequest()->getParam($key);

      if ($value) {
        $params[$key] = $value;
      }
    }

    $this->_redirectUrl(Mage::getUrl('checkout/cart', array('_query' => $params)));
  }

  /**
   * Save cart email action
   */
  public function saveEmailAction()
  {
    $email = $this->getRequest()->getParam('email');

    if (!Zend_Validate::is($email, 'EmailAddress')) {
      $response = array(
        'saved' => false,
        'error' => 'invalid_email'
      );
    } else {
      $cart = Mage::getSingleton('checkout/cart');
      $quote = $cart->getQuote();

      // Save email to quote object.
      $quote->setCustomerEmail($email);
      $quote->save();

      $response = array(
        'saved' => true
      );
    }

    $this->getResponse()->setHeader('Content-type', 'application/json');
    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
    return;
  }

  /**
   * Klaviyo extension status action
   */
  public function statusAction()
  {
    $nonce = $this->getRequest()->getParam('nonce');

    if (!$nonce) {
      $response = array('data' => NULL);
    } else {
      $helper = Mage::helper('klaviyo_reclaim');

      $version = (string) Mage::getConfig()->getNode('modules/Klaviyo_Reclaim/version');

      $config_details = $this->_getExtensionConfigDetails();

      $since_minutes = 60;
      $cron_details = $this->_getCronScheduleDetails($since_minutes);

      $num_quotes = 5;
      $quote_details = $this->_getQuoteDetails($num_quotes);

      $response = array(
        'data' => array(
          'version' => $version,
          'config'  => $config_details,
          'cron'    => $cron_details,
          'quotes'  => $quote_details
        )
      );
    }

    $this->getResponse()->setHeader('Content-type', 'application/json');
    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }

  protected function _getExtensionConfigDetails () {
    $helper = Mage::helper('klaviyo_reclaim');

    return array(
      'global' => array(
        'enabled' => $helper->isEnabled(),
        'api_key' => $helper->getPublicApiKey() != NULL
      )
    );
  }

  protected function _getCronScheduleDetails ($since_minutes) {
    $adapter = Mage::getSingleton('core/resource')->getConnection('sales_read');
    $since = Zend_Date::now();
    $since->sub($since_minutes, Zend_Date::MINUTE);
    $since = $adapter->convertDateTime($since);

    $query = $this->_getKlaviyoCronScheduleBaseQuery()
      ->addFieldToFilter('status', Mage_Cron_Model_Schedule::STATUS_SUCCESS)
      ->addFieldToFilter('finished_at', array('gteq' => $since))
      ->addOrder('finished_at', $direction='desc');

    $has_suceeded = $query->count() > 0;
    $last_success = array();

    if ($has_suceeded) {
      $job = $query->getFirstItem();

      $last_success = array(
        'id'          => $job->getScheduleId(),
        'finished_at' => Mage::getSingleton('core/date')->gmtDate($job->getFinishedAt()),
        'messages'    => $job->getMessages()
      );
    }

    $query = $this->_getKlaviyoCronScheduleBaseQuery()
      ->addFieldToFilter('status', array('in' => array(
        Mage_Cron_Model_Schedule::STATUS_MISSED,
        Mage_Cron_Model_Schedule::STATUS_ERROR
      )))
      ->addFieldToFilter('created_at', array('gteq' => $since))
      ->addOrder('finished_at', $direction='desc');

    $has_failed = $query->count() > 0;
    $failures = array();

    if ($has_failed) {
      foreach ($query as $job) {
        $failures[] = array(
          'id'       => $job->getScheduleId(),
          'status'   => $job->getStatus(),
          'messages' => $job->getMessage()
        );
      }
    }

    return array(
      'last_success' => $last_success,
      'failures'     => $failures
    );
  }

  protected function _getKlaviyoCronScheduleBaseQuery () {
    return Mage::getModel('cron/schedule')->getCollection()
      ->addFieldToFilter('job_code', 'klaviyo_track_quotes');
  }

  protected function _getQuoteDetails ($num_quotes) {
    $helper = Mage::helper('klaviyo_reclaim');

    $has_checkout_ids = Mage::getModel('klaviyo_reclaim/checkout')->getCollection()->count() > 0;

    $query = Mage::getResourceModel('sales/quote_collection')
      ->addFieldToFilter('converted_at', array('null' => true))
      ->addOrder('updated_at', $direction='desc')
      ->setPageSize($num_quotes)
      ->setCurPage(1);

    $quotes = array();
    foreach ($query as $quote) {
      $store = $quote->getStore();
      $email = $quote->getCustomerEmail();

      if ($email) {
        $pieces = explode('@', $email, 2);

        // Obfuscates the email address from `someone@example.com` to `so******@example.com`.
        $email = substr($pieces[0], 0, 2) . str_repeat('*', 6) . '@' . $pieces[1];
      }

      $quotes[] = array(
        'id'             => $quote->getEntityId(),
        'store_id'       => $quote->getStoreId(),
        'gmt_created_at'     => Mage::getSingleton('core/date')->gmtDate($quote->getCreatedAt()),
        'gmt_updated_at'     => Mage::getSingleton('core/date')->gmtDate($quote->getUpdatedAt()),
        'customer_email' => $email,
        'remote_ip'      => $quote->getRemoteIp(),
        'num_items'      => count($quote->getItemsCollection()),
        'is_active'      => $quote->getIsActive(),

        'config'         => array(
          'is_enabled' => $helper->isEnabled($store),
          'api_key'    => $helper->getPublicApiKey($store) != NULL
        )
      );
    }

    return array(
      'has_checkout_ids' => $has_checkout_ids,
      'quotes' => $quotes
    );
  }
}
