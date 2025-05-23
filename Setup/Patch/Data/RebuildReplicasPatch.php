<?php

namespace Algolia\AlgoliaSearch\Setup\Patch\Data;

use Algolia\AlgoliaSearch\Helper\Entity\ProductHelper;
use Algolia\AlgoliaSearch\Registry\ReplicaState;
use Algolia\AlgoliaSearch\Service\AlgoliaCredentialsManager;
use Algolia\AlgoliaSearch\Service\Product\ReplicaManager;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class RebuildReplicasPatch implements DataPatchInterface
{
    public function __construct(
        protected ModuleDataSetupInterface  $moduleDataSetup,
        protected StoreManagerInterface     $storeManager,
        protected ReplicaManager            $replicaManager,
        protected ProductHelper             $productHelper,
        protected AppState                  $appState,
        protected ReplicaState              $replicaState,
        protected AlgoliaCredentialsManager $algoliaCredentialsManager,
        protected LoggerInterface           $logger
    )
    {}

        /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [
            MigrateVirtualReplicaConfigPatch::class
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): PatchInterface
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area code is already set - nothing to do
        }

        $storeIds = array_keys($this->storeManager->getStores());
        // Delete all replicas before resyncing in case of incorrect replica assignments
        foreach ($storeIds as $storeId) {
            if (!$this->algoliaCredentialsManager->checkCredentialsWithSearchOnlyAPIKey($storeId)) {
                $this->logger->warning("Algolia credentials are not configured for store $storeId. Skipping auto replica rebuild for this store. If you need to rebuild your replicas run `bin/magento algolia:replicas:rebuild`");
                continue;
            }

            $this->replicaManager->deleteReplicasFromAlgolia($storeId);
        }

        foreach ($storeIds as $storeId) {
            if (!$this->algoliaCredentialsManager->checkCredentialsWithSearchOnlyAPIKey($storeId)) {
                continue;
            }

            $this->replicaState->setChangeState(ReplicaState::REPLICA_STATE_CHANGED, $storeId); // avoids latency
            $this->replicaManager->syncReplicasToAlgolia($storeId, $this->productHelper->getIndexSettings($storeId));
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
