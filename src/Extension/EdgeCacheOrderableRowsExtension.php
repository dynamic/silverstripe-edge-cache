<?php

namespace Dynamic\EdgeCache\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\ManyManyList;

/**
 * Purges when an editor reorders the records of a many_many relation in a GridField.
 *
 * GridFieldOrderableRows writes the sort column of a plain many_many join table with a raw query,
 * which no add/remove callback sees. The component announces the reorder, so this forwards it to
 * the purge callback EdgeCachePurgeable registered on the list. Many_many through and has_many
 * lists reorder by saving records, which purges through the record's own events.
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
        if (!$list instanceof ManyManyList) {
            return;
        }

        $callback = $list->addCallbacks()->get(EdgeCachePurgeable::RELATION_CALLBACK);
        if ($callback) {
            $callback($list);
        }
    }
}
