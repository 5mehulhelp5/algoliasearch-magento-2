<?php

namespace Algolia\AlgoliaSearch\Helper\Entity;

use Algolia\AlgoliaSearch\Helper\AlgoliaHelper;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Helper\Logger;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Helper\Stock;
use Magento\CatalogRule\Model\ResourceModel\Rule;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Directory\Helper\Data as CurrencyDirectory;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\Currency as CurrencyHelper;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Helper\Data;

abstract class BaseHelper
{
    protected $config;
    protected $logger;
    protected $algoliaHelper;
    protected $eavConfig;

    protected $storeManager;
    protected $eventManager;
    protected $currencyManager;
    protected $taxHelper;
    protected $visibility;

    protected static $_activeCategories;
    protected static $_categoryNames;
    protected $stock;
    protected $stockRegistry;
    protected $currencyHelper;
    protected $currencyDirectory;
    protected $objectManager;
    protected $catalogHelper;
    protected $queryResource;
    protected $filterProvider;
    protected $rule;
    protected $priceCurrency;
    protected $cache;

    protected $storeUrls;

    private $coreCategories;
    private $idColumn;

    private $isCategoryVisibleInMenuCache;

    abstract protected function getIndexNameSuffix();

    public function __construct(Config $eavConfig,
                                ConfigHelper $configHelper,
                                AlgoliaHelper $algoliaHelper,
                                Logger $logger,
                                StoreManagerInterface $storeManager,
                                ManagerInterface $eventManager,
                                Visibility $visibility,
                                Stock $stock,
                                Data $taxHelper,
                                StockRegistryInterface $stockRegistry,
                                CurrencyDirectory $currencyDirectory,
                                CurrencyHelper $currencyHelper,
                                ObjectManagerInterface $objectManager,
                                CatalogHelper $catalogHelper,
                                ResourceConnection $queryResource,
                                Currency $currencyManager,
                                FilterProvider $filterProvider,
                                PriceCurrencyInterface $priceCurrency,
                                Rule $rule,
                                ConfigCache $cache)
    {
        $this->eavConfig = $eavConfig;
        $this->config = $configHelper;
        $this->algoliaHelper = $algoliaHelper;
        $this->logger = $logger;

        $this->storeManager = $storeManager;
        $this->eventManager = $eventManager;
        $this->currencyManager = $currencyManager;
        $this->stockRegistry = $stockRegistry;
        $this->visibility = $visibility;
        $this->stock = $stock;
        $this->taxHelper = $taxHelper;
        $this->currencyHelper = $currencyHelper;
        $this->currencyDirectory = $currencyDirectory;
        $this->objectManager = $objectManager;
        $this->catalogHelper = $catalogHelper;
        $this->queryResource = $queryResource;
        $this->filterProvider = $filterProvider;
        $this->priceCurrency = $priceCurrency;
        $this->rule = $rule;
        $this->cache = $cache;
    }

    public function getBaseIndexName($storeId = null)
    {
        return (string) $this->config->getIndexPrefix($storeId) . $this->storeManager->getStore($storeId)->getCode();
    }

    public function getIndexName($storeId = null, $tmp = false)
    {
        return (string) $this->getBaseIndexName($storeId) . $this->getIndexNameSuffix() . ($tmp ? '_tmp' : '');
    }

    protected function try_cast($value)
    {
        if (is_numeric($value) && floatval($value) == floatval(intval($value))) {
            return intval($value);
        }

        if (is_numeric($value)) {
            return floatval($value);
        }

        return $value;
    }

    protected function castProductObject(&$productData)
    {
        $nonCastableAttributes = array('sku', 'name', 'description');

        foreach ($productData as $key => &$data) {
            if (in_array($key, $nonCastableAttributes, true) === true) {
                continue;
            }

            $data = $this->try_cast($data);

            if (is_array($data) === false) {
                $data = explode('|', $data);

                if (count($data) == 1) {
                    $data = $data[0];
                    $data = $this->try_cast($data);
                } else {
                    foreach ($data as &$element) {
                        $element = $this->try_cast($element);
                    }
                }
            }
        }
    }

    protected function strip($s, $completeRemoveTags = array())
    {
        if (!empty($completeRemoveTags) && $s) {
            $dom = new \DOMDocument();
            if (@$dom->loadHTML(mb_convert_encoding($s, 'HTML-ENTITIES', 'UTF-8'))) {
                $toRemove = array();
                foreach ($completeRemoveTags as $tag) {
                    $removeTags = $dom->getElementsByTagName($tag);

                    foreach ($removeTags as $item) {
                        $toRemove[] = $item;
                    }
                }

                foreach ($toRemove as $item) {
                    $item->parentNode->removeChild($item);
                }

                $s = $dom->saveHTML();
            }
        }

        $s = html_entity_decode($s, null, 'UTF-8');

        $s = trim(preg_replace('/\s+/', ' ', $s));
        $s = preg_replace('/&nbsp;/', ' ', $s);
        $s = preg_replace('!\s+!', ' ', $s);
        $s = preg_replace('/\{\{[^}]+\}\}/', ' ', $s);
        $s = strip_tags($s);
        $s = trim($s);

        return $s;
    }

    public function isCategoryActive($categoryId, $storeId = null)
    {
        $storeId = intval($storeId);
        $categoryId = intval($categoryId);

        if ($path = $this->getCategoryPath($categoryId, $storeId)) {
            // Check whether the specified category is active

            $isActive = true; // Check whether all parent categories for the current category are active
            $parentCategoryIds = explode('/', $path);

            if (count($parentCategoryIds) <= 2) { // Exclude root category
                return false;
            }

            array_shift($parentCategoryIds); // Remove root category

            array_pop($parentCategoryIds); // Remove current category as it is already verified

            $parentCategoryIds = array_reverse($parentCategoryIds); // Start from the first parent

            foreach ($parentCategoryIds as $parentCategoryId) {
                if (!($parentCategoryPath = $this->getCategoryPath($parentCategoryId, $storeId))) {
                    $isActive = false;
                    break;
                }
            }

            if ($isActive) {
                return true;
            }
        }

        return false;
    }

    public function getCategoryPath($categoryId, $storeId = null)
    {
        $categories = $this->getCategories();
        $storeId = intval($storeId);
        $categoryId = intval($categoryId);
        $path = null;

        $categoryKeyId = $categoryId;

        if ($this->getCorrectIdColumn() === 'row_id') {
            $category = $this->getCategoryById($categoryId);
            if ($category) {
                $categoryKeyId = $category->getRowId();
            }
        }

        if(is_null($categoryKeyId)) {
            return $path;
        }

        $key = $storeId . '-' . $categoryKeyId;

        if (isset($categories[$key])) {
            $path = ($categories[$key]['value'] == 1) ? strval($categories[$key]['path']) : null;
        } elseif ($storeId !== 0) {
            $key = '0-' . $categoryKeyId;

            if (isset($categories[$key])) {
                $path = ($categories[$key]['value'] == 1) ? strval($categories[$key]['path']) : null;
            }
        }

        return $path;
    }

    public function getCategories()
    {
        if (is_null(self::$_activeCategories)) {
            self::$_activeCategories = [];

            /** @var \Magento\Catalog\Model\ResourceModel\Category $resource */
            $resource = $this->objectManager->create('\Magento\Catalog\Model\ResourceModel\Category');

            if ($attribute = $resource->getAttribute('is_active')) {
                $connection = $this->queryResource->getConnection();
                $select = $connection->select()
                    ->from(['backend' => $attribute->getBackendTable()], ['key' => new \Zend_Db_Expr("CONCAT(backend.store_id, '-', backend.".$this->getCorrectIdColumn().")"), 'category.path', 'backend.value'])
                    ->join(['category' => $resource->getTable('catalog_category_entity')], 'backend.'.$this->getCorrectIdColumn().' = category.'.$this->getCorrectIdColumn(), [])
                    ->where('backend.attribute_id = ?', $attribute->getAttributeId())
                    ->order('backend.store_id')
                    ->order('backend.'.$this->getCorrectIdColumn());

                self::$_activeCategories = $connection->fetchAssoc($select);
            }
        }

        return self::$_activeCategories;
    }

    public function isCategoryVisibleInMenu($categoryId, $storeId)
    {
        $key = $categoryId.' - '.$storeId;
        if (isset($this->isCategoryVisibleInMenuCache[$key])) {
            return $this->isCategoryVisibleInMenuCache[$key];
        }

        $categoryId = (int) $categoryId;

        /** @var Category $category */
        $category = $this->objectManager->create('\Magento\Catalog\Model\Category');
        $category = $category->setStoreId($storeId)->load($categoryId);

        $this->isCategoryVisibleInMenuCache[$key] = (bool) $category->getIncludeInMenu();

        return $this->isCategoryVisibleInMenuCache[$key];
    }

    public function getCategoryName($categoryId, $storeId = null)
    {
        if ($categoryId instanceof \Magento\Catalog\Model\Category) {
            $categoryId = $categoryId->getId();
        }

        if ($storeId instanceof  \Magento\Store\Model\Store) {
            $storeId = $storeId->getId();
        }

        $categoryId = intval($categoryId);
        $storeId = intval($storeId);

        if (is_null(self::$_categoryNames)) {
            self::$_categoryNames = [];

            /** @var \Magento\Catalog\Model\ResourceModel\Category $categoryModel */
            $categoryModel = $this->objectManager->create('\Magento\Catalog\Model\ResourceModel\Category');

            if ($attribute = $categoryModel->getAttribute('name')) {
                $connection = $this->queryResource->getConnection();

                $select = $connection->select()
                    ->from(['backend' => $attribute->getBackendTable()], [new \Zend_Db_Expr("CONCAT(backend.store_id, '-', backend.".$this->getCorrectIdColumn().")"), 'backend.value'])
                    ->join(['category' => $categoryModel->getTable('catalog_category_entity')], 'backend.'.$this->getCorrectIdColumn().' = category.'.$this->getCorrectIdColumn(), [])
                    ->where('backend.attribute_id = ?', $attribute->getAttributeId())
                    ->where('category.level > ?', 1);

                self::$_categoryNames = $connection->fetchPairs($select);
            }
        }

        $categoryName = null;

        $categoryKeyId = $categoryId;

        if ($this->getCorrectIdColumn() === 'row_id') {
            $category = $this->getCategoryById($categoryId);
            if ($category) {
                $categoryKeyId = $category->getRowId();
            }
        }

        if(is_null($categoryKeyId)) {
            return $categoryName;
        }

        $key = $storeId . '-' . $categoryKeyId;

        if (isset(self::$_categoryNames[$key])) {
            // Check whether the category name is present for the specified store
            $categoryName = strval(self::$_categoryNames[$key]);
        } elseif ($storeId != 0) {
            // Check whether the category name is present for the default store
            $key = '0-' . $categoryKeyId;
            if (isset(self::$_categoryNames[$key])) {
                $categoryName = strval(self::$_categoryNames[$key]);
            }
        }

        return $categoryName;
    }

    public function getStores($store_id)
    {
        $store_ids = [];

        if ($store_id == null) {
            foreach ($this->storeManager->getStores() as $store) {
                if ($this->config->isEnabledBackEnd($store->getId()) === false) {
                    continue;
                }

                if ($store->getIsActive()) {
                    $store_ids[] = $store->getId();
                }
            }
        } else {
            $store_ids = [$store_id];
        }

        return $store_ids;
    }

    /**
     * @param $store_id
     *
     * @return Url
     */
    public function getStoreUrl($store_id)
    {
        if ($this->storeUrls == null) {
            $this->storeUrls = [];
            $storeIds = $this->getStores(null);

            foreach ($storeIds as $storeId) {
                // ObjectManager used instead of UrlFactory because UrlFactory will return UrlInterface which
                // may cause a backend Url object to be returned
                $url = $this->objectManager->create('Magento\Framework\Url');
                $url->setStore($storeId);
                $this->storeUrls[$storeId] = $url;
            }
        }

        if (array_key_exists($store_id, $this->storeUrls)) {
            return $this->storeUrls[$store_id];
        }

        return null;
    }

    protected function getCoreCategories() {
        if (isset($this->coreCategories)) {
            return $this->coreCategories;
        }

        $categoriesData = $this->objectManager->create('Magento\Catalog\Model\ResourceModel\Category\Collection');
        $categoriesData
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('include_in_menu', '1')
            ->addFieldToFilter('level', ['gt' => 1])
            ->addIsActiveFilter();

        $this->coreCategories = [];
        foreach ($categoriesData as $category) {
            $this->coreCategories[$category->getId()] = $category;
        }

        return $this->coreCategories;
    }

    protected function getCategoryById($categoryId)
    {
        $catagories = $this->getCoreCategories();

        return isset($catagories[$categoryId]) ? $catagories[$categoryId] : null;
    }

    protected function getCorrectIdColumn()
    {
        if (isset($this->idColumn)) {
            return $this->idColumn;
        }

        $this->idColumn = 'entity_id';

        if ($this->config->getMagentoEdition() !== 'Community' && version_compare($this->config->getMagentoVersion(), '2.1.0', '>=')) {
            $this->idColumn = 'row_id';
        }

        return $this->idColumn;
    }
}
