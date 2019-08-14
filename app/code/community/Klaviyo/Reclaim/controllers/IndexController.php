<?php

/**
 * Reclaim frontend controller
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_IndexController extends Mage_Core_Controller_Front_Action
{
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
    $checkout_id = $this->getRequest()->getParam('id');

    if ($checkout_id) {
      $checkout = Mage::getModel('klaviyo_reclaim/checkout');
      $checkout->load($checkout_id);

      if ($checkout->getId()) {
        $saved_quote = Mage::getModel('sales/quote');
        $saved_quote->load($checkout->getQuoteId());

        $cart = Mage::getSingleton('checkout/cart');

        if ($saved_quote->getId() != $cart->getQuote()->getId() && !$cart->getItemsCount()) {
          foreach ($saved_quote->getItemsCollection() as $quote_item) {
            $quote_item_product = Mage::getModel('catalog/product')->load($quote_item->getProduct()->getId());

            // Don't add configurable products for now.
            if ($quote_item_product->isConfigurable()) {
              continue;
            }

            if ($quote_item_product->getId()) {
              $cart->addProduct($quote_item_product, $quote_item->getQty());
              $cart->save();
            }
          }
        }
      }
    }
    
    $this->_redirectUrl(Mage::getUrl('checkout/cart'));
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
        ->addFieldToFilter('finished_at', $hour_ago)
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