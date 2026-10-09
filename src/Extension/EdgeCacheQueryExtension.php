<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\CollectionState;
use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\RelationList;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Tags a page with every class it queries while rendering, so a page that lists blog posts, staff
 * or testimonials is purged when one of them is published, with no declaration on the page.
 *
 * Applied to DataObject. It records only while a front-end GET is being rendered in an enabled
 * environment (EdgeCache::isCollecting()), and never queries the database itself.
 *
 * Silverstripe lazy-loads a record's subclass fields with a query of its own class. When that
 * record is the page being rendered it says nothing about what the page lists, so it is not a tag
 * (every blog post would otherwise be tied to every other blog post). When it is some other record,
 * such as a listed post whose summary the template reads, it is.
 *
 * It also hooks many_many lists: a join write fires no record event, so the list's add/remove
 * callbacks purge instead, for EdgeCachePurgeable and for the pages and elements on either end of
 * the list. Applied here because the list can be edited from either side of the relation, and the
 * class that opted in may be the other one.
 */
class EdgeCacheQueryExtension extends DataExtension
{
    /**
     * Join tables of lists Silverstripe rewrites on every save of a record that holds links or
     * images in its content (link and file tracking). They say nothing about what a page shows, so
     * changing them purges nothing.
     *
     * @config
     * @var string[]
     */
    private static $ignored_join_tables = ['SiteTreeLink', 'FileLink'];

    public function augmentLoadLazyFields(SQLSelect $query, DataQuery $dataQuery, DataObject $dataObject): void
    {
        if (!EdgeCache::isCollecting()) {
            return;
        }

        // Silverstripe has already run the query hook once for this query; take that tag back.
        $edge = EdgeCache::singleton();
        if ($added = CollectionState::takeAdded($dataQuery)) {
            $edge->forgetTag($added);
        }

        CollectionState::markLazy($dataQuery);
        $edge->collectLazyClass($dataQuery->dataClass(), (int) $dataObject->ID);
    }

    /**
     * Purge when the members of a many_many list (plain or through) change, for whichever of the
     * record handing out the list and the class it lists uses EdgeCachePurgeable. The other side is
     * the class as declared on the relation, so a subclass tag is not purged from there. A list of
     * Settings records edited from the other side clears the site, as editing it from Settings does
     * (EdgeCacheSiteConfigExtension). A list of members, or any list a member hands out (a group's
     * members, a member's groups), purges the pages that listed members.
     *
     * A page or an element on either end of the list purges the pages that show it, with no opt-in:
     * the join table is not versioned, so the change is live without a publish.
     */
    public function updateManyManyComponents(RelationList $list): void
    {
        $classes = [];
        foreach ([get_class($this->owner), $list->dataClass()] as $class) {
            if (singleton($class)->hasExtension(EdgeCachePurgeable::class)) {
                $classes[$class] = $class;
            }
        }
        $settings = is_a($list->dataClass(), SiteConfig::class, true);
        $memberClass = is_a($list->dataClass(), Member::class, true) ? $list->dataClass() : null;
        $memberClass ??= $this->owner instanceof Member ? get_class($this->owner) : null;
        $showing = $this->pagesShowingListed($list);
        if (!$classes && !$settings && !$memberClass && !$showing) {
            return;
        }

        $callback = function ($list = null, $changed = null) use ($classes, $settings, $memberClass, $showing): void {
            if ($settings) {
                PurgeQueue::singleton()->addEverything();
            }
            if ($memberClass) {
                PurgeQueue::singleton()->addTags(EdgeCache::classChainTags($memberClass));
            }
            foreach ($classes as $class) {
                EdgeCachePurgeable::purgeClass($class);
            }
            if ($showing) {
                $showing($this->changedIds($list, $changed), $list);
            }
        };
        $list->addCallbacks()->add($callback, EdgeCachePurgeable::RELATION_CALLBACK);
        $list->removeCallbacks()->add($callback, EdgeCachePurgeable::RELATION_CALLBACK);
    }

    /**
     * What to purge when the list's members change because a page or an element is on one end of
     * it: the list's owner when it is one, and the members the change touched when the list holds
     * them. Null when neither end is a page or an element, or for a list core rewrites on its own.
     *
     * @return callable(int[], mixed): void|null
     */
    private function pagesShowingListed(RelationList $list): ?callable
    {
        $joinTable = method_exists($list, 'getJoinTable') ? $list->getJoinTable() : null;
        if ($joinTable && in_array($joinTable, (array) Config::inst()->get(static::class, 'ignored_join_tables'), true)) {
            return null;
        }

        $owner = $this->owner;
        $ownerIsPage = $owner instanceof SiteTree;
        $ownerIsElement = $owner->hasExtension(EdgeCacheElementExtension::class);
        $listedClass = $list->dataClass();
        $listsPages = is_a($listedClass, SiteTree::class, true);
        $listsElements = singleton($listedClass)->hasExtension(EdgeCacheElementExtension::class);
        if (!$ownerIsPage && !$ownerIsElement && !$listsPages && !$listsElements) {
            return null;
        }

        return function (
            array $changedIds,
            $changedList
        ) use (
            $owner,
            $ownerIsPage,
            $ownerIsElement,
            $listedClass,
            $listsPages,
            $listsElements
        ): void {
            // A list handed out by a singleton (DataList::relation()) carries the owners' IDs, set
            // after this extension sees it, and the record has none.
            $ownerIds = $owner->exists() || !$changedList instanceof RelationList
                ? [(int) $owner->ID]
                : array_map('intval', (array) $changedList->getForeignID());
            if ($ownerIsPage) {
                $this->purgePages($ownerIds);
            } elseif ($ownerIsElement) {
                $elements = $owner->exists() ? [$owner] : $owner::get()->byIDs($ownerIds);
                foreach ($elements as $element) {
                    // Provided by EdgeCacheElementExtension, checked above.
                    $element->purgeOwnerPage();
                }
            }

            if ($listsPages) {
                $this->purgePages($changedIds);
            } elseif ($listsElements && $changedIds) {
                foreach ($listedClass::get()->byIDs($changedIds) as $element) {
                    $element->purgeOwnerPage(); // @phpstan-ignore method.notFound
                }
            }
        };
    }

    /**
     * Purge pages by ID, and the virtual pages that copy them: a virtual page renders its source's
     * lists but is tagged only with its own ID, and a list change republishes nothing.
     *
     * @param int[] $pageIds
     */
    private function purgePages(array $pageIds): void
    {
        $pageIds = array_filter($pageIds);
        if (!$pageIds) {
            return;
        }

        if (class_exists(VirtualPage::class)) {
            $pageIds = array_merge(
                $pageIds,
                array_map('intval', VirtualPage::get()->filter('CopyContentFromID', $pageIds)->column('ID'))
            );
        }
        PurgeQueue::singleton()->addTags(array_map([EdgeCache::class, 'pageTag'], array_unique($pageIds)));
    }

    /**
     * The IDs a list callback was told about: the record added, the IDs removed, or, for a reorder,
     * which names no record, every member of the list.
     *
     * @param mixed $list
     * @param mixed $changed a record, a record ID, or an array of IDs
     * @return int[]
     */
    private function changedIds($list, $changed): array
    {
        if (is_array($changed)) {
            return array_map('intval', $changed);
        }
        if ($changed instanceof DataObject) {
            return [(int) $changed->ID];
        }
        if (is_numeric($changed)) {
            return [(int) $changed];
        }

        return $list instanceof RelationList ? array_map('intval', $list->column('ID')) : [];
    }

    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null): void
    {
        if (!EdgeCache::isCollecting() || !$dataQuery || CollectionState::isLazy($dataQuery)) {
            return;
        }

        $added = EdgeCache::singleton()->collectClass($dataQuery->dataClass());
        CollectionState::rememberAdded($dataQuery, $added);
    }
}
