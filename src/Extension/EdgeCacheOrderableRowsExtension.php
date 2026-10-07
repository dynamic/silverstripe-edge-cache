<?php

namespace Dynamic\EdgeCache\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\RelationList;

/**
 * Purges when an editor reorders the records of a many_many relation in a GridField.
 *
 * GridFieldOrderableRows writes the sort column of a plain many_many join table with a raw query, and
 * of a many_many through list by saving the join record, which is not a record the site opts in. No
 * add/remove callback sees either. The component announces the reorder, so this forwards it to the
 * purge callback EdgeCacheQueryExtension registered on the list. A has_many list has none, and
 * reorders by saving the listed records, which purge through their own events.
 *
 * Applied to Symbiote\GridFieldExtensions\GridFieldOrderableRows when that module is installed.
 */
class EdgeCacheOrderableRowsExtension extends Extension
{
    /**
     * @param mixed $list
     * @param array $values
     * @param array $sortedIDs
     */
    public function onAfterReorderItems($list, $values, $sortedIDs): void
    {
        if (!$list instanceof RelationList) {
            return;
        }

        $callback = $list->addCallbacks()->get(EdgeCachePurgeable::RELATION_CALLBACK);
        if ($callback) {
            $callback($list);
        }
    }
}
