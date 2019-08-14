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
   * Save cart email action
   */
  public function statusAction()
  {
    $nonce = $this->getRequest()->getParam('nonce');

    if (!$nonce) {
      $response = array('data' => NULL);
    } else {
      $helper = Mage::helper('klaviyo_reclaim');

      $is_enabled = $helper->isEnabled();
      $is_api_key_set = $helper->getPublicApiKey() != NULL;

      $adapter = Mage::getSingleton('core/resource')->getConnection('sales_read');
      $hour_ago = Zend_Date::now();
      $hour_ago->sub(60, Zend_Date::MINUTE);
      $hour_ago = $adapter->convertDateTime($hour_ago);

      $is_cron_running = Mage::getModel('cron/schedule')->getCollection()
        ->addFieldToFilter('status', Mage_Cron_Model_Schedule::STATUS_SUCCESS)
        ->addFieldToFilter('finished_at', array('gteq' => $hour_ago))
        ->count() > 0;

      $has_reclaim_entries = Mage::getModel('klaviyo_reclaim/checkout')->getCollection()->count() > 0;

      $response = array(
        'data' => array($is_enabled, $is_api_key_set, $is_cron_running, $has_reclaim_entries)
      );
    }

    $this->getResponse()->setHeader('Content-type', 'application/json');
    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
    return;
  }
}