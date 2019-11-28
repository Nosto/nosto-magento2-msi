<?php /** @noinspection PhpUnused */

/**
 * Copyright (c) 2019, Nosto Solutions Ltd
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
 * @copyright 2019 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Msi\Model\Service\Stock\Provider;

use Magento\Catalog\Model\Product;
use Magento\Inventory\Model\ResourceModel\SourceItem\CollectionFactory as InventorySourceItemCollectionFactory;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Store\Model\Website;
use Nosto\Tagging\Logger\Logger;
use Nosto\Tagging\Model\ResourceModel\Magento\Product\CollectionFactory as ProductCollectionFactory;

class DefaultMsiStockProvider implements MsiStockProviderInterface
{
    /** @var GetProductSalableQtyInterface */
    private $salableQty;

    /** @var StockByWebsiteIdResolverInterface */
    private $stockByWebsiteIdResolver;

    /** @var InventorySourceItemCollectionFactory */
    private $inventorySourceItemCollectionFactory;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var GetSourcesAssignedToStockOrderedByPriorityInterface */
    private $stockSources;

    /** @var Logger */
    private $logger;

    /** @var IsProductSalableInterface */
    private $isProductSalable;

    public function __construct(
        GetProductSalableQtyInterface $salableQty,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        InventorySourceItemCollectionFactory $inventorySourceItemCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        GetSourcesAssignedToStockOrderedByPriorityInterface $stockSources,
        IsProductSalableInterface $isProductSalable,
        Logger $logger
    ) {
        $this->salableQty = $salableQty;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->inventorySourceItemCollectionFactory = $inventorySourceItemCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->stockSources = $stockSources;
        $this->isProductSalable = $isProductSalable;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function isInStock(Product $product, Website $website)
    {
        return $this->isProductSalable->execute(
            $product->getSku(),
            $this->getStockByWebsite($website)->getStockId()
        );
    }

    /**
     * @inheritDoc
     */
    public function getQuantitiesByIds(array $productIds, Website $website)
    {
        $products = $this->productCollectionFactory->create()
            ->addIdsToFilter($productIds);
        $skuStrings = [];
        /* @var Product $item */
        foreach ($products as $item) {
            $skuStrings[$item->getId()] = $item->getSku();
        }
        $quantities = [];
        $inventorySources = $this->getStockSourcesByWebsite($website);
        $sourceStrings = [];
        /* @var SourceInterface $inventorySource */
        foreach ($inventorySources as $inventorySource) {
            $sourceStrings[] = $inventorySource->getSourceCode();
        }
        $inventoryItems = $this->inventorySourceItemCollectionFactory->create()
            ->addFieldToFilter(SourceItemInterface::SKU, $skuStrings)
            ->addFieldToFilter(SourceItemInterface::SOURCE_CODE, $sourceStrings);
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
     * @inheritDoc
     */
    public function getAvailableQuantity(Product $product, Website $website)
    {
        $stock = $this->getStockByWebsite($website);
        try {
            return $this->salableQty->execute($product->getSku(), $stock->getStockId());
        } catch (\Exception $e) {
            $this->logger->exception($e);
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getStockByWebsite(Website $website)
    {
        return $this->stockByWebsiteIdResolver->execute($website->getId());
    }

    /**
     * @inheritDoc
     */
    public function getStockSourcesByWebsite(Website $website)
    {
        try {
            return $this->stockSources->execute($this->getStockByWebsite($website)->getStockId());
        } catch (\Exception $e) {
            $this->logger->exception($e);
            return null;
        }
    }
}
