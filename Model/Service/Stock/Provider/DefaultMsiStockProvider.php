<?php
/**
 * Copyright (c) 2020, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2020 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Msi\Model\Service\Stock\Provider;

use Magento\Catalog\Model\Product;
use Magento\Inventory\Model\ResourceModel\SourceItem\Collection;
use Magento\Inventory\Model\ResourceModel\SourceItem\CollectionFactory as InventorySourceItemCollectionFactory;
use Magento\Inventory\Model\SourceItem;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Store\Model\Website;
use Nosto\Tagging\Logger\Logger;
use Nosto\Tagging\Model\ResourceModel\Magento\Product\CollectionFactory as ProductCollectionFactory;
use Nosto\Tagging\Model\Service\Stock\Provider\StockProviderInterface;

class DefaultMsiStockProvider implements StockProviderInterface
{
    /** @var GetProductSalableQtyInterface */
    private $salableQty;

    /** @var StockByWebsiteIdResolverInterface */
    private $stockResolver;

    /** @var InventorySourceItemCollectionFactory */
    private $inventorySourceItemCollectionFactory;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var GetSourcesAssignedToStockOrderedByPriorityInterface */
    private $stockSourcesResolver;

    /** @var Logger */
    private $logger;

    /** @var IsProductSalableInterface */
    private $isProductSalable;

    /**
     * @param GetProductSalableQtyInterface $salableQty
     * @param StockByWebsiteIdResolverInterface $stocksResolver
     * @param InventorySourceItemCollectionFactory $inventorySourceItemCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param GetSourcesAssignedToStockOrderedByPriorityInterface $stockSourcesResolver
     * @param IsProductSalableInterface $isProductSalable
     * @param Logger $logger
     */
    public function __construct(
        GetProductSalableQtyInterface $salableQty,
        StockByWebsiteIdResolverInterface $stocksResolver,
        InventorySourceItemCollectionFactory $inventorySourceItemCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        GetSourcesAssignedToStockOrderedByPriorityInterface $stockSourcesResolver,
        IsProductSalableInterface $isProductSalable,
        Logger $logger
    ) {
        $this->salableQty = $salableQty;
        $this->stockResolver = $stocksResolver;
        $this->inventorySourceItemCollectionFactory = $inventorySourceItemCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->stockSourcesResolver = $stockSourcesResolver;
        $this->isProductSalable = $isProductSalable;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function isInStock(Product $product, Website $website): bool
    {
        $stockId = $this->getStockByWebsite($website)->getStockId();
        if (!$stockId) {
            return false;
        }
        return $this->isProductSalable->execute(
            $product->getSku(),
            $stockId
        );
    }

    /**
     * @inheritDoc
     */
    public function getQuantitiesByIds(array $productIds, Website $website): array
    {
        $quantities = [];
        $skuStrings = $this->getProductIdSkuMap($productIds, $website);
        $inventoryItems = $this->getInventoryItemsByProductIds($productIds, $website);
        if ($inventoryItems == null || empty($skuStrings)) {
            return [];
        }
        /** @var SourceItemInterface $inventoryItem */
        foreach ($inventoryItems as $inventoryItem) {
            $productId = array_search($inventoryItem->getSku(), $skuStrings, true);
            if ($productId !== false) {
                if (!isset($quantities[$productId])) {
                    $quantities[$productId] = 0;
                }
                $quantities[$productId] += $inventoryItem->getQuantity();
            }
        }
        return $quantities;
    }

    /**
     * @param array $productIds
     * @param Website $website
     * @return Collection|SourceItem[]|null
     */
    private function getInventoryItemsByProductIds(array $productIds, Website $website)
    {
        $skuStrings = $this->getProductIdSkuMap($productIds, $website);
        if (empty($skuStrings)) {
            return null;
        }
        $inventorySources = $this->getStockSourcesByWebsite($website);
        $sourceStrings = [];
        /* @var SourceInterface $inventorySource */
        foreach ($inventorySources as $inventorySource) {
            $sourceStrings[] = $inventorySource->getSourceCode();
        }
        return $this->inventorySourceItemCollectionFactory
            ->create()
            ->addFieldToFilter(SourceItemInterface::SOURCE_CODE, $sourceStrings)
            ->addFieldToFilter(SourceItemInterface::SKU, $skuStrings);
    }

    /**
     * @param array $productIds
     * @param Website $website
     * @return array
     */
    private function getProductIdSkuMap(array $productIds, Website $website)
    {
        $products = $this->productCollectionFactory->create()
            ->addIdsToFilter($productIds)
            ->addWebsiteFilter($website->getId());

        $productIdToSkuMap = [];
        /* @var Product $item */
        foreach ($products as $item) {
            $productIdToSkuMap[$item->getId()] = $item->getSku();
        }
        return $productIdToSkuMap;
    }
    /**
     * @inheritDoc
     */
    public function getAvailableQuantity(Product $product, Website $website)
    {
        $stock = $this->getStockByWebsite($website);
        $stockId = $stock->getStockId();
        try {
            if ($stockId) {
                return (int)$this->salableQty->execute($product->getSku(), $stockId);
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
        }
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getStockByWebsite(Website $website): StockInterface
    {
        return $this->stockResolver->execute($website->getId());
    }

    /**
     * @inheritDoc
     */
    public function getStockSourcesByWebsite(Website $website)
    {
        $stockId = $this->getStockByWebsite($website)->getStockId();
        try {
            if ($stockId) {
                return $this->stockSourcesResolver->execute($stockId);
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getInStockProductIds(array $productIds, Website $website)
    {
        $inStockSkus = [];
        $inventoryItems = $this->getInventoryItemsByProductIds($productIds, $website);
        if ($inventoryItems == null) {
            return [];
        }
        $productIdSkuMap = $this->getProductIdSkuMap($productIds, $website);
        foreach ($inventoryItems as $inventoryItem) {
            $productId = array_search($inventoryItem->getSku(), $productIdSkuMap);
            if ($inventoryItem->getStatus() == SourceItemInterface::STATUS_IN_STOCK && $productId) {
                $inStockSkus[] = $productId;
            }
        }
        return $inStockSkus;
    }
}
