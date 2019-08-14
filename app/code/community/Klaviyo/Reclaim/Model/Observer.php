<?php
/**
 * @category    Klaviyo
 * @package     Klaviyo_Reclaim
 * @copyright   Copyright (c) 2013 Klaviyo Inc. (http://www.klaviyo.com)
 */


/**
 * Reclaim Observer
 *
 * @category   Klaviyo
 * @package    Klaviyo_Reclaim
 * @author     Klaviyo Team <support@klaviyo.com>
 */
class Klaviyo_Reclaim_Model_Observer
{
    /**
     * Error messages
     *
     * @var array
     */
    protected $_errors = array();

    /**
     * Track Sales Quotes
     *
     * @return Klaviyo_Reclaim_Model_Observer
     */
    public function trackQuotes()
    {

        if (!Mage::helper('klaviyo_reclaim')->isEnabled()) {
            return;
        }

        $public_api_key = Mage::helper('klaviyo_reclaim')->getPublicApiKey();

        if (!$public_api_key) {
            return;
        }

        $tracker = new Klaviyo_Reclaim_Model_Tracker($public_api_key);

        $this->_errors = array();
        try {
            $adapter = Mage::getSingleton('core/resource')->getConnection('sales_read');

            // Find quotes that are at least 15 minutes old and have been updated in the last 60 minutes.
            $created_window_minutes = 15;
            
            $created_before = Zend_Date::now();
            $created_before->sub($created_window_minutes, Zend_Date::MINUTE);
            $created_before = $adapter->convertDateTime($created_before);

            $updated_window_minutes = 60;
            
            $updated_after = Zend_Date::now();
            $updated_after->sub($updated_window_minutes, Zend_Date::MINUTE);
            $updated_after = $adapter->convertDateTime($updated_after);

            $quotes = Mage::getResourceModel('sales/quote_collection')
                ->addFieldToFilter('converted_at', array('null' => true))
                ->addFieldToFilter('created_at', array('lteq' => $created_before))
                ->addFieldToFilter('updated_at', array('gteq' => $updated_after));

            $quotes_tracked = 0;

            foreach ($quotes as $quote) {
                
                $billing_address = $quote->getBillingAddress();

                if (!$billing_address) {
                    continue;
                }

                // Skip quotes that don't have an email or remote_ip set. Checking the IP is our best guess
                // at not sending emails for quotes created on the Magento backend. There
                // doesn't seem a reliable way to tell if a quote is from the frontend or backend.
                if (!$quote->getCustomerEmail() || is_null($quote->getRemoteIp())) {
                    continue;
                }

                $quote_id = $quote->getId();

                $checkout = Mage::helper('klaviyo_reclaim')->getCheckout($quote_id);

                $customer_properties = array(
                    '$email' => $quote->getCustomerEmail(),
                    '$first_name' => $quote->getCustomerFirstname(),
                    '$last_name' => $quote->getCustomerLastname(),
                    'location' => array(
                        'source' => 'magento',
                        'address1' => $billing_address->getStreet(1),
                        'city' => $billing_address->getCity(),
                        'region' => $billing_address->getRegion(),
                        'zip' => $billing_address->getPostcode(),
                        'country' => $billing_address->getCountry()
                    )
                );

                if ($billing_address->getStreet(2)) {
                    $customer_properties['location']['address2'] = $billing_address->getStreet(2);
                }

                $item_descriptions = array();
                $item_details = array();
                $item_count = 0;

                $configurable_product_ids = array();

                foreach ($quote->getItemsCollection() as $quote_item) {
                    $quote_item_quantity = $quote_item->getQty();
                    $quote_item_product = Mage::getModel('catalog/product')->load($quote_item->getProduct()->getId());
                    $quote_item_product_name = $quote_item_product->getName();

                    for ($i = 0; $i < $quote_item_quantity; $i++) {
                        $item_descriptions[] = $quote_item_product_name;
                        $item_count++;
                    }

                    $product_images = array();
                    foreach ($quote_item_product->getMediaGalleryImages() as $product_image) {
                        if (!$product_image->getDisabled()) {
                            $product_images[] = array(
                                'url' => $product_image->getUrl()
                            );
                        }
                    }

                    if ($quote_item_product->isConfigurable()) {
                        $configurable_product_ids[] = $quote_item_product->getId();
                    }

                    $item_details[] = array(
                        'quantity' => (float) $quote_item_quantity,
                        'row_total' => (float) $quote_item->getBaseRowTotal(),
                        'row_discount' => (float) $quote_item->getBaseDiscountAmount(),
                        'product' => array(
                            'id' => $quote_item_product->getId(),
                            'sku' => $quote_item_product->getSKU(),
                            'name' => $quote_item_product->getName(),
                            'price' => (float) $quote_item_product->getPrice(),
                            'images' => $product_images
                        )
                    );
                }

                if (!empty($configurable_product_ids)) {
                    $model_configurable = Mage::getModel('catalog/product_type_configurable');
                    $tmp = array();

                    // Look for simple products that are really represented by a configurable product.
                    foreach ($item_details as $line_index => $line) {
                        $product_id = $line['product']['id'];
                        if (in_array($product_id, $configurable_product_ids) || $line['row_total']) {
                            $tmp[] = $line;
                            continue;
                        }

                        $configurable_parent_ids = $model_configurable->getParentIdsByChild($product_id);
                        $common_ids = array_intersect($configurable_product_ids, $configurable_parent_ids);

                        // If it's a simple product placeholder for a configurable product, discard it. Otherwise, keep it.
                        if (empty($common_ids)) {
                            $tmp[] = $line;
                        }
                    }

                    // We use the temporary array so when we json_encode we get an array, not an object.
                    $item_details = $tmp;
                }

                // Skip quotes that don't have items.
                if (empty($item_details)) {
                    continue;
                }

                $checkout_id = $checkout->getId();
                $checkout_url = $quote->getStore()->getUrl('reclaim/index/view', array('_query' => array('id' => $checkout_id)));

                $properties = array(
                    '$event_id' => $quote_id,
                    '$value' => (float) $quote->getGrandTotal(),
                    'Items' => $item_descriptions,
                    'Items Count' => $item_count,
                    '$extra' => array(
                        'checkout_url' => $checkout_url,
                        'checkout_id' => $checkout_id,
                        'line_items' => $item_details,
                    )
                );

                $coupon_code = $quote->getCouponCode();
                if ($coupon_code) {
                    $totals = $quote->getTotals();
                    $properties['Discount Codes'] = array($coupon_code);
                    $properties['Total Discounts'] = (float) $totals['discount']->getValue() * -1;
                }

                $timestamp = strtotime($quote->getUpdatedAt());

                $tracker->track('Checkout Started', $customer_properties, $properties, $timestamp);

                if (!$quote->getIsActive()) {
                    $tracker->track('Checkout Completed', $customer_properties, $properties, $timestamp);
                }

                $quotes_tracked++;
            }
        } catch (Exception $e) {
            $this->_errors[] = $e->getMessage();
            $this->_errors[] = $e->getTrace();
            Mage::log($e->getMessage(), Zend_Log::ERR);
            Mage::logException($e);
        }

        return Mage::helper('core')->jsonEncode(array('tracked' => $quotes_tracked));
    }

    public function syncSubscriber (Varien_Event_Observer $observer) {
        // Mage::getSingleton('klaviyo_reclaim/api')->lists();

        if (!Mage::helper('klaviyo_reclaim')->isEnabled()) {
            return $observer;
        }

        $private_api_key = Mage::helper('klaviyo_reclaim')->getPrivateApiKey();

        if (!$private_api_key) {
            return $observer;
        }

        $subscriber = $observer->getEvent()->getSubscriber();
        $email = $subscriber->getSubscriberEmail();

        if ($subscriber->getBulksync()) {
            return $observer;
        }

        if ($subscriber->getStoreId()) {
            $list_id = Mage::helper('klaviyo_reclaim')->getSubscriptionList($subscriber->getStoreId());
        } else {
            $list_id = Mage::helper('klaviyo_reclaim')->getSubscriptionList(Mage::app()->getStore()->getId());
        }

        $subscriber->setImportMode(true);

        $is_requiring_confirmation = false;
        if (!Mage::helper('klaviyo_reclaim')->isAdmin() &&
            (Mage::getStoreConfig(Mage_Newsletter_Model_Subscriber::XML_PATH_CONFIRMATION_FLAG, $subscriber->getStoreId()) == 1)) {
            $is_requiring_confirmation = true;
        }

        $subscriber_status = $subscriber->getStatus();

        if ($subscriber_status == Mage_Newsletter_Model_Subscriber::STATUS_UNCONFIRMED ||
            $subscriber_status == Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED) {

            $response = Mage::getSingleton('klaviyo_reclaim/api')->listSubscriberAdd($list_id, $email, $is_requiring_confirmation);

            if (!is_array($response) || !isset($response['already_member'])) {
                // Handle error better.
                return $observer;
            }

            if ($response['already_member']) {
                $subscriber->setStatus(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED);
            } else if ($is_requiring_confirmation) {
                Mage::getSingleton('core/session')->addSuccess(Mage::helper('klaviyo_reclaim')->__('An email to confirm your subscription has been sent to your inbox.'));
            }
        } else if ($subscriber_status == Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED) {
            Mage::getSingleton('klaviyo_reclaim/api')->listSubscriberDelete($list_id, $email);
        }
    }

    public function syncSubscriberDelete (Varien_Event_Observer $observer) {
        if (!Mage::helper('klaviyo_reclaim')->isEnabled()) {
            return $observer;
        }

        $private_api_key = Mage::helper('klaviyo_reclaim')->getPrivateApiKey();

        if (!$private_api_key) {
            return $observer;
        }

        $subscriber = $observer->getEvent()->getSubscriber();
        $email = $subscriber->getSubscriberEmail();

        $subscriber->setImportMode(true);

        if ($subscriber->getBulksync()) {
            return $observer;
        }

        if ($subscriber->getStoreId()) {
            $list_id = Mage::helper('klaviyo_reclaim')->getSubscriptionList($subscriber->getStoreId());
        } else {
            $list_id = Mage::helper('klaviyo_reclaim')->getSubscriptionList(Mage::app()->getStore()->getId());
        }

        Mage::getSingleton('klaviyo_reclaim/api')->listSubscriberDelete($list_id, $email);
    }

    public function syncCustomer (Varien_Event_Observer $observer) {
        // Mage::log($observer);
    }
}
