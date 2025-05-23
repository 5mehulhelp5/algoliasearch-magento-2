<?php

namespace Algolia\AlgoliaSearch\Api\Builder;

interface IndexBuilderInterface
{
    public function buildIndex(int $storeId, ?array $entityIds, ?array $options): void;

    public function buildIndexFull(int $storeId, ?array $options): void;
}
