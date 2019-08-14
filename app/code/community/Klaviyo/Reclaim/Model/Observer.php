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
     * Cache for booleans for whether the Klaviyo is enabled for each store.
     *
     * @var array
     */
    var $is_enabled_cache = array();

    /**
     * Cache for tracker objects for each store.
     *
     * @var array
     */
    var $tracker_cache = array();

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
    public function trackQuotes() {
        $this->_errors = array();

        $quotes_found = 0;
        $quotes_tracked = 0;

        try {
            // Find quotes that are at least 15 minutes old and have been updated in the last 60 minutes.
            $created_window_minutes = 15;
            $updated_window_minutes = 60;

            $quotes = $this->_fetchRecentQuotes($created_window_minutes, $updated_window_minutes);

            $quotes_found = count($quotes);
            $quotes_tracked = 0;

            foreach ($quotes as $quote) {
                if ($this->_maybeTrackQuote($quote)) {
                    $quotes_tracked++;
                }
            }
        } catch (Exception $e) {
            $this->_errors[] = $e->getMessage();
            $this->_errors[] = $e->getTrace();
            Mage::log($e->getMessage(), Zend_Log::ERR, Mage::helper('klaviyo_reclaim')->getLogFile());
            Mage::logException($e);
        }

        // Log results in case we need to debug.
        $json = Mage::helper('core')->jsonEncode(array(
            'cron_job'     => 'klaviyo_track_quotes',
            'found'    => $quotes_found,
            'tracked'  => $quotes_tracked
        ));
        Mage::log($json, Zend_Log::INFO, Mage::helper('klaviyo_reclaim')->getLogFile());

        return $this;
    }

    /**
     * Read quotes that have been updated recently, but were created at least X minutes ago.
     *
     * @param $created_window_minutes (int)
     * @param $updated_window_minutes (int)
     * @return Mage_Sales_Model_Resource_Quote_Collection
     */
    protected function _fetchRecentQuotes ($created_window_minutes, $updated_window_minutes) {
        $adapter = Mage::getSingleton('core/resource')->getConnection('sales_read');

        $created_before = Zend_Date::now();
        $created_before->sub($created_window_minutes, Zend_Date::MINUTE);
        $created_before = $adapter->convertDateTime($created_before);

        $updated_after = Zend_Date::now();
        $updated_after->sub($updated_window_minutes, Zend_Date::MINUTE);
        $updated_after = $adapter->convertDateTime($updated_after);

        return Mage::getResourceModel('sales/quote_collection')
            ->addFieldToFilter('converted_at', array('null' => true))
            ->addFieldToFilter('created_at', array('lteq' => $created_before))
            ->addFieldToFilter('updated_at', array('gteq' => $updated_after));
    }

    /**
     * Send tracking information to Klaviyo for a quote if it has the necessary information.
     *
     * @param $quote (Mage_Sales_Model_Quote)
     * @return bool
     */
    protected function _maybeTrackQuote ($quote) {
        if (!$this->_isEnabledForStore($quote->getStore())) {
            return false;
        }

        $tracker = $this->getTracker($quote);
        $billing_address = $quote->getBillingAddress();

        if (!$billing_address || !$tracker) {
            return false;
        }

        // Skip quotes that don't have an email or remote_ip set. Checking the IP is our best guess
        // at not sending emails for quotes created on the Magento backend. There
        // doesn't seem a reliable way to tell if a quote is from the frontend or backend.
        if (!$quote->getCustomerEmail() || is_null($quote->getRemoteIp())) {
            return false;
        }

        $quote_id = $quote->getId();

        $checkout = Mage::helper('klaviyo_reclaim')->getCheckout($quote_id);

        $customer_properties = $this->_customerPropertiesForQuote($quote, $billing_address);

        $item_details = array();

        $configurable_product_ids = array();

        foreach ($quote->getItemsCollection() as $quote_item) {
            $response = $this->_quoteItemData($quote_item);
            $quote_item_data = $response['quote_item'];
            $is_product_configurable = $response['is_product_configurable'];

            // Configurable product - The "product" when the product has multiple SKUs, e.g. "t-shirt"
            // Simple product - The "SKU," e.g. "red, medium t-shirt." For products that don't have options or variations,
            // this is the "product."
            // http://docs.magento.com/m1/ce/user_guide/catalog/product-create.html
            if ($is_product_configurable) {
                $configurable_product_ids[] = $quote_item_data['product']['id'];
            }

            $item_details[] = $quote_item_data;
        }

        $response = $this->_mergeConfigurableAndSimpleQuoteItems($item_details, $configurable_product_ids);
        $item_details = $response['items'];
        $item_count = $response['item_count'];
        $item_descriptions = $response['item_descriptions'];

        // Skip quotes that don't have items.
        if (empty($item_details)) {
            return false;
        }

        $checkout_id = $checkout->getId();
        $checkout_url = $quote->getStore()->getUrl('reclaim/index/view', array('_query' => array('id' => $checkout_id)));

        $properties = array(
            '$event_id'   => $quote_id,
            '$value'      => (float) $quote->getGrandTotal(),
            'Items'       => $item_descriptions,
            'Items Count' => $item_count,
            '$extra'      => array(
                'checkout_url' => $checkout_url,
                'checkout_id'  => $checkout_id,
                'line_items'   => $item_details,
            )
        );

        $coupon_code = $quote->getCouponCode();
        if ($coupon_code) {
            $totals = $quote->getTotals();
            $properties['Discount Codes'] = array($coupon_code);
            if (array_key_exists('discount', $totals) && is_object($totals['discount'])) {
                $properties['Total Discounts'] = (float) $totals['discount']->getValue() * -1;
            }
        }

        $timestamp = strtotime($quote->getUpdatedAt());

        $tracker->track('Checkout Started', $customer_properties, $properties, $timestamp);

        if (!$quote->getIsActive()) {
            $tracker->track('Checkout Completed', $customer_properties, $properties, $timestamp);
        }

        return true;
    }

    /**
     * Normalize customer and billing address data to an array that's JSON serializable.
     *
     * @param $quote (Mage_Sales_Model_Quote)
     * @param $billing_address (Mage_Sales_Model_Quote_Address)
     * @return array
     */
    protected function _customerPropertiesForQuote($quote, $billing_address) {
        $properties = array(
            '$email'            => $quote->getCustomerEmail(),
            '$first_name'       => $quote->getCustomerFirstname(),
            '$last_name'        => $quote->getCustomerLastname(),
            'Magento Store'     => $quote->getStore()->getName(),
            'Magento WebsiteID' => $quote->getStore()->getWebsiteId(),
            'location'          => array(
                'source'   => 'magento',
                'address1' => $billing_address->getStreet(1),
                'city'     => $billing_address->getCity(),
                'region'   => $billing_address->getRegion(),
                'zip'      => $billing_address->getPostcode(),
                'country'  => $billing_address->getCountry()
            )
        );

        if ($billing_address->getStreet(2)) {
            $properties['location']['address2'] = $billing_address->getStreet(2);
        }

        return $properties;
    }

    /**
     * Normalize quote item (line item) to an array that's JSON serializable.
     *
     * @param $quote_item (Mage_Sales_Model_Quote_Item)
     * @return array
     */
    protected function _quoteItemData ($quote_item) {
        $data = array();

        $quote_item_quantity = $quote_item->getQty();
        $quote_item_product = Mage::getModel('catalog/product')->load($quote_item->getProduct()->getId());

        $product_details = array(
            'id'    => $quote_item_product->getId(),
            'sku'   => $quote_item_product->getSKU(),
            'name'  => $quote_item_product->getName(),
            'price' => (float) $quote_item_product->getPrice(),
            'special_price' => (float) $quote_item_product->getFinalPrice()
        );

        $product_images = array();
        // If we have a simple product for this configurable product, get the simple name
        // and also attempt to get the images
        if ($option = $quote_item->getOptionByCode('simple_product')) {
            $simple_product = Mage::getModel('catalog/product')->load($option->getProduct()->getId());

            $product_details['simple_name'] = $simple_product->getName();
            foreach ($simple_product->getMediaGalleryImages() as $product_image) {
                if (!$product_image->getDisabled()) {
                    $product_images[] = array(
                        'url' => $product_image->getUrl()
                    );
                }
            }
        }

        // if there is no simple product or we tried but couldn't find any
        // images for the simple product just grab the configurable images
        if (empty($product_images)) {
            foreach ($quote_item_product->getMediaGalleryImages() as $product_image) {
                if (!$product_image->getDisabled()) {
                    $product_images[] = array(
                        'url' => $product_image->getUrl()
                    );
                }
            }
        }
        $product_details['images'] = $product_images;

        return array(
            'is_product_configurable' => $quote_item_product->isConfigurable(),
            'quote_item'              => array(
                'quantity'     => (float) $quote_item_quantity,
                'row_total'    => (float) $quote_item->getBaseRowTotal(),
                'row_discount' => (float) $quote_item->getBaseDiscountAmount(),
                'product'      => $product_details
            )
        );
    }

    /**
     * Merged quote item (line item) details in cases where there are two line items to represent
     * a configurable product and its options.
     *
     * @param $item_details (array)
     * @param $configurable_product_ids (array)
     * @return array
     */
    protected function _mergeConfigurableAndSimpleQuoteItems($item_details, $configurable_product_ids) {
        $model_configurable = Mage::getModel('catalog/product_type_configurable');

        $merged = array();
        $item_descriptions = array();
        $item_count = 0;

        // Look for simple products that are really represented by a configurable product.
        foreach ($item_details as $line_index => $line) {
            $product_id = $line['product']['id'];
            if (in_array($product_id, $configurable_product_ids) || $line['row_total']) {
                $merged[] = $line;
            } else {
                $configurable_parent_ids = $model_configurable->getParentIdsByChild($product_id);
                $common_ids = array_intersect($configurable_product_ids, $configurable_parent_ids);

                // If it's a simple product placeholder for a configurable product, discard it. Otherwise, keep it.
                if (empty($common_ids)) {
                    $merged[] = $line;
                } else {
                    continue;
                }
            }

            if (array_key_exists('simple_name', $line['product'])) {
              $item_descriptions[] = $line['product']['simple_name'];
            } else {
              $item_descriptions[] = $line['product']['name'];
            }

            $item_count += $line['quantity'];
        }

        return array(
            'items'             => $merged,
            'item_count'        => $item_count,
            'item_descriptions' => $item_descriptions
        );
    }

    public function syncSubscriber (Varien_Event_Observer $observer) {
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
            $subscriber_status == Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED ||
            $subscriber_status == Mage_Newsletter_Model_Subscriber::STATUS_NOT_ACTIVE) {

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

    protected function _isEnabledForStore($store) {
        $store_id = $store->getId();

        if (!isset($this->is_enabled_cache[$store_id])) {
            $this->is_enabled_cache[$store_id] = Mage::helper('klaviyo_reclaim')->isEnabled($store);
        }

        return $this->is_enabled_cache[$store_id];
    }

    public function getTracker($quote) {
        $store_id = $quote->getStoreId();

        foreach($this->tracker_cache as $id => $tracker){
            if($id == $store_id){
                return $tracker;
            }
        }

        $public_api_key = Mage::helper('klaviyo_reclaim')->getPublicApiKey($store_id);

        if(!$public_api_key){
            return NULL;
        }

        $tracker =  new Klaviyo_Reclaim_Model_Tracker($public_api_key);
        $this->tracker_cache[$store_id] = $tracker;

        return $tracker;
    }
}
