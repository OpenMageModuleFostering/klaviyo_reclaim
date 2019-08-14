<?php
class Klaviyo_Reclaim_Block_Tracking_Product extends Mage_Core_Block_Template
{
    /**
     * Retrieve current product model
     *
     * @return Mage_Catalog_Model_Product
     */
    public function getProduct()
    {
        if (!Mage::registry('product') && $this->getProductId()) {
            $product = Mage::getModel('catalog/product')->load($this->getProductId());
            Mage::register('product', $product);
        }
        return Mage::registry('product');
    }

    /**
     * Retrieve current product categories.
     *
     * @return str
     */
    public function getProductCategoriesAsJson()
    {
        $categories = array();

        foreach ($this->getProduct()->getCategoryIds() as $category_id) {
          $category = Mage::getModel('catalog/category')->load($category_id); 
          $categories[] = $category->getName();
        }

        return json_encode($categories);
    }
}