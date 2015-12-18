<?php
/**
 * Codisto eBay Sync Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    Codisto
 * @package     Codisto_Sync
 * @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Codisto_Sync_Model_Indexer_Ebay extends Mage_Index_Model_Indexer_Abstract
{

	const EVENT_MATCH_RESULT_KEY = 'codisto_sync_match_result';

	public function getName()
	{
		return 'eBay Index';
	}

	public function getDescription()
	{
		return 'Index Catalog data for synchronisation with eBay';
	}

	public function matchEvent(Mage_Index_Model_Event $event)
	{
		$data = $event->getNewData();
		$type = $event->getType();

		if (isset($data[self::EVENT_MATCH_RESULT_KEY])) {
			return $data[self::EVENT_MATCH_RESULT_KEY];
		}

		$entity = $event->getEntity();

		if($entity == Mage_Catalog_Model_Product::ENTITY)
		{
			if($type == Mage_Index_Model_Event::TYPE_SAVE ||
				$type == Mage_Index_Model_Event::TYPE_DELETE)
			{
				$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
				return true;
			}
		}

		if($entity == Mage_CatalogInventory_Model_Stock_Item::ENTITY)
		{
			if($type == Mage_Index_Model_Event::TYPE_SAVE ||
				$type == Mage_Index_Model_Event::TYPE_DELETE)
			{
				$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
				return true;
			}
		}

		if($entity == Mage_Catalog_Model_Category::ENTITY)
		{
			if($type == Mage_Index_Model_Event::TYPE_SAVE ||
				$type == Mage_Index_Model_Event::TYPE_DELETE)
			{
				$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
			}
			return true;
		}

		if($entity == Mage_Core_Model_Store::ENTITY ||
			$entity == Mage_Core_Model_Store_Group::ENTITY)
		{
			$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);
			return true;
		}

		$result = parent::matchEvent($event);

		$event->addNewData(self::EVENT_MATCH_RESULT_KEY, $result);

		return $result;
	}

	protected function _registerEvent(Mage_Index_Model_Event $event)
	{
		$event->addNewData(self::EVENT_MATCH_RESULT_KEY, true);

		$entity = $event->getEntity();

		switch ($entity) {
			case Mage_Catalog_Model_Product::ENTITY:

				$event->addNewData('codisto_sync_product', true);
				break;

			case Mage_CatalogInventory_Model_Stock_Item::ENTITY:

				$event->addNewData('codisto_sync_stock', true);
				break;

			case Mage_Catalog_Model_Category::ENTITY:

				$event->addNewData('codisto_sync_category', true);
				break;

			case Mage_Catalog_Model_Convert_Adapter_Product::ENTITY:

				$event->addNewData('codisto_sync_bulkproduct', true);
				break;

			case Mage_Core_Model_Store::ENTITY:
			case Mage_Core_Model_Store_Group::ENTITY:

				$event->addNewData('codisto_sync_store', true);
				break;
		}
		return $this;
	}

	protected function _processEvent(Mage_Index_Model_Event $event)
	{
		$data = $event->getNewData();
		$type = $event->getType();

		if(isset($data['codisto_sync_category']))
		{
			if($event->getDataObject())
			{
				// always synchronise the admin store on any edit
				$syncStores = array(0);

				$eventData = $event->getDataObject()->getData();
				$storeId = isset($eventData['store_id']) ? $eventData['store_id'] : 0;
				if(!isset($storeId) || !$storeId)
					$storeId = 0;

				if($storeId != 0)
				{
					$defaultMerchantId = (int)Mage::getConfig()->getNode('stores/admin/codisto/merchantid');
					$storeMerchantId = (int)Mage::getStoreConfig('codisto/merchantid', $storeId);

					// if the default Codisto merchantid is different at this store level
					// explicitly synchronise it as well
					if($defaultMerchantId != $storeMerchantId)
					{
						$syncStores[] = $storeId;
					}
				}

				$syncObject = Mage::getModel('codistosync/sync');

				$categoryId = $event->getDataObject()->getId();

				$client = new Zend_Http_Client();
				$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();

				foreach($syncStores as $storeId)
				{
					$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

					if(Mage_Index_Model_Event::TYPE_SAVE == $type)
						$syncObject->UpdateCategory($syncDb, $categoryId, $storeId);
					else
						$syncObject->DeleteCategory($syncDb, $categoryId, $storeId);

					try
					{
						$merchantid = Mage::getStoreConfig('codisto/merchantid', $storeId);
						$hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

						$merchantlist = Zend_Json::decode($merchantid);
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantid)
						{
							$client->setUri('https://api.codisto.com/'.$merchantid);
							$client->setHeaders('X-HostKey', $hostkey);
							$client->setRawData('action=sync&categoryid='.$categoryid)->request('POST');
						}
					}
					catch (Exception $e)
					{

					}
				}
			}
		}

		if(isset($data['codisto_sync_stock']))
		{
			if($event->getDataObject())
			{
				// always synchronise the admin store on any edit
				$syncStores = array(0);

				$eventData = $event->getDataObject()->getData();
				$storeId = isset($eventData['store_id']) ? $eventData['store_id'] : 0;
				if(!isset($storeId) || !$storeId)
					$storeId = 0;

				if($storeId != 0)
				{
					$defaultMerchantId = (int)Mage::getConfig()->getNode('stores/admin/codisto/merchantid');
					$storeMerchantId = (int)Mage::getStoreConfig('codisto/merchantid', $storeId);

					// if the default Codisto merchantid is different at this store level
					// explicitly synchronise it as well
					if($defaultMerchantId != $storeMerchantId)
					{
						$syncStores[] = $storeId;
					}
				}

				$syncObject = Mage::getModel('codistosync/sync');
				$syncIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($event->getDataObject()->getProductId());

				if(empty($syncIds))
					$syncIds = array($event->getDataObject()->getProductId());

				$productIds = '';
				if(count($syncIds) == 1)
					$productIds = $syncIds[0];
				else
					$productIds = '['.implode(',', $syncIds).']';

				$client = new Zend_Http_Client();
				$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();

				foreach($syncStores as $storeId)
				{
					$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

					if($type == Mage_Index_Model_Event::TYPE_DELETE
							&& count($syncIds) == 1
							&& $syncIds[0] == $event->getDataObject()->getId())
					{
						$syncObject->DeleteProduct($syncDb, $event->getDataObject()->getId(), $storeId);
					}
					else
					{
						$syncObject->UpdateProducts($syncDb, $syncIds, $storeId);
					}

					try
					{
						$merchantid = Mage::getStoreConfig('codisto/merchantid', $storeId);
						$hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

						$merchantlist = Zend_Json::decode($merchantid);
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantid)
						{
							$client->setUri('https://api.codisto.com/'.$merchantid);
							$client->setHeaders('X-HostKey', $hostkey);
							$client->setRawData('action=sync&productid='.$productIds)->request('POST');
						}
					}
					catch (Exception $e)
					{

					}
				}
			}
		}

		if(isset($data['codisto_sync_product']))
		{
			if($event->getDataObject())
			{
				// always synchronise the admin store on any edit
				$syncStores = array(0);

				$eventData = $event->getDataObject()->getData();
				$storeId = isset($eventData['store_id']) ? $eventData['store_id'] : 0;
				if(!isset($storeId) || !$storeId)
					$storeId = 0;

				if($storeId != 0)
				{
					$defaultMerchantId = (int)Mage::getConfig()->getNode('stores/admin/codisto/merchantid');
					$storeMerchantId = (int)Mage::getStoreConfig('codisto/merchantid', $storeId);

					// if the default Codisto merchantid is different at this store level
					// explicitly synchronise it as well
					if($defaultMerchantId != $storeMerchantId)
					{
						$syncStores[] = $storeId;
					}
				}

				$syncObject = Mage::getModel('codistosync/sync');
				$syncIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($event->getDataObject()->getId());

				if(empty($syncIds))
					$syncIds = array($event->getDataObject()->getId());

				$productIds = '';
				if(count($syncIds) == 1)
					$productIds = $syncIds[0];
				else
					$productIds = '['.implode(',', $syncIds).']';

				$client = new Zend_Http_Client();
				$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0 ));
				$client->setStream();

				foreach($syncStores as $storeId)
				{
					$syncDb = Mage::getBaseDir('var') . '/codisto-ebay-sync-'.$storeId.'.db';

					if($type == Mage_Index_Model_Event::TYPE_DELETE
							&& count($syncIds) == 1
							&& $syncIds[0] == $event->getDataObject()->getId())
					{
						$syncObject->DeleteProduct($syncDb, $event->getDataObject()->getId(), $storeId);
					}
					else
					{
						$syncObject->UpdateProducts($syncDb, $syncIds, $storeId);
					}

					try
					{
						$merchantid = Mage::getStoreConfig('codisto/merchantid', $storeId);
						$hostkey = Mage::getStoreConfig('codisto/hostkey', $storeId);

						$merchantlist = Zend_Json::decode($merchantid);
						if(!is_array($merchantlist))
							$merchantlist = array($merchantlist);

						foreach($merchantlist as $merchantid)
						{
							$client->setUri('https://api.codisto.com/'.$merchantid);
							$client->setHeaders('X-HostKey', $hostkey);
							$client->setRawData('action=sync&productid='.$productIds)->request('POST');
						}
					}
					catch (Exception $e)
					{

					}
				}
			}
		}

		if(isset($data['codisto_sync_store']))
		{
			$merchants = array();
			$visited = array();

			$stores = Mage::getModel('core/store')->getCollection();

			foreach($stores as $store)
			{
				$merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
				if($merchantlist)
				{
					if(!is_array($merchantlist))
						$merchantlist = array($merchantlist);

					foreach($merchantlist as $merchantId)
					{
						if(!in_array($merchantId, $visited, true))
						{
							$merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
							$visited[] = $merchantId;
						}
					}
				}
			}

			unset($visited);


			$client = new Zend_Http_Client();
			$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0, 'timeout' => 2 ));
			$client->setStream();

			foreach($merchants as $merchant)
			{
				try
				{
					$client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
					$client->setHeaders('X-HostKey', $merchant['hostkey']);
					$client->setRawData('action=syncstores')->request('POST');
				}
				catch(Exception $e)
				{

				}
			}
		}

		if(isset($data['codisto_sync_category']))
		{
			$this->reindexAll();
		}
	}

	public function reindexAll()
	{
		$merchants = array();
		$visited = array();

		$stores = Mage::getModel('core/store')->getCollection();

		foreach($stores as $store)
		{
			$merchantlist = Zend_Json::decode($store->getConfig('codisto/merchantid'));
			if($merchantlist)
			{
				if(!is_array($merchantlist))
					$merchantlist = array($merchantlist);

				foreach($merchantlist as $merchantId)
				{
					if(!in_array($merchantId, $visited, true))
					{
						$merchants[] = array( 'merchantid' => $merchantId, 'hostkey' => $store->getConfig('codisto/hostkey'), 'storeid' => $store->getId() );
						$visited[] = $merchantId;
					}
				}
			}
		}

		unset($visited);

		$client = new Zend_Http_Client();
		$client->setConfig(array( 'keepalive' => true, 'maxredirects' => 0 ));

		foreach($merchants as $merchant)
		{
			try
			{
				$client->setUri('https://api.codisto.com/'.$merchant['merchantid']);
				$client->setHeaders('X-HostKey', $merchant['hostkey']);
				$client->setRawData('action=sync')->request('POST');
			}
			catch(Exception $e)
			{

			}
		}
	}
}
