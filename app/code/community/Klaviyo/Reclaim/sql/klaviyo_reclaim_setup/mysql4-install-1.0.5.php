<?php

/**
 * Klaviyo Reclaim installation script
 *
 * @auther Klaviyo Team (support@klaviyo.com)
 */

$installer = $this;

$installer->startSetup();

/*
  The following code generates the index name / type below. The function and class below are available in Magento 1.6+

  $index_name = $installer->getIdxName(
    $installer->getTable("klaviyo_reclaim/checkout"),
    array("quote_id"),
    Varien_Db_Adapter_Interface::INDEX_TYPE_INDEX
  );
  $index_type = Varien_Db_Adapter_Interface::INDEX_TYPE_INDEX);

*/

$index_name = "idx_klaviyo_reclaim_checkout_quote_id";
$index_type = "index";

$table = $installer->getConnection()
  ->newTable($installer->getTable("klaviyo_reclaim/checkout"))
  ->addColumn("checkout_id", Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, array(
    "nullable" => false,
    "primary" => true
  ), "Entity ID")
  ->addColumn("quote_id", Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    "unsigned" => true,
    "nullable" => false,
    "primary" => true
  ), "Quote ID")
  ->addIndex(
    $index_name,
    array("quote_id"),
    array("type" => $index_type)
  );

$installer->getConnection()->createTable($table);
