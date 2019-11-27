<?php

namespace Nosto\Msi\Model\Service\Stock\Provider;

use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\Store\Model\Website;
use Nosto\Tagging\Model\Service\Stock\Provider\StockProviderInterface;

interface MsiStockProviderInterface extends StockProviderInterface
{
    /**
     * @param Website $website
     * @return StockInterface
     */
    public function getStockByWebsite(Website $website);

    /**
     * @param Website $website
     * @return SourceInterface[]
     */
    public function getStockSourcesByWebsite(Website $website);
}
