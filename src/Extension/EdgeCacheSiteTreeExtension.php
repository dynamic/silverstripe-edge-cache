<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\Versioned\Versioned;

/**
 * Purges the edge when a page reaches or leaves the Live stage.
 *
 * A page's own tag clears its own URL (every query-string variant included), the tags of its
 * ancestors clear the holder pages that list it, and its class tags clear pages that list records
 * of that class (a home page showing recent posts). A change to anything the navigation or footer
 * shows (title, URL, position, menu visibility) clears the whole site instead, since those render
 * on every page.
 *
 * @property SiteTree|static $owner
 */
class EdgeCacheSiteTreeExtension extends DataExtension
{
    /**
     * Fields whose change alters every page's navigation.
     *
     * @config
     * @var string[]
     */
    private static $structural_fields = ['Title', 'MenuTitle', 'URLSegment', 'ShowInMenus', 'Sort', 'ParentID'];

    /**
     * `publishSingle()` path (CMS publish button, `publishRecursive()`).
     *
     * @param DataObject|null $original the Live record before this publish
     */
    public function onAfterPublish(&$original): void
    {
        $this->purgeRecord($original);
    }

    /**
     * `copyVersionToStage()` path (the content API publishes this way). Runs before the copy, while
     * Live still holds the old record, so the two can be compared.
     */
    public function onBeforeVersionedPublish($fromStage, $toStage): void
    {
        if ($toStage !== Versioned::LIVE) {
            return;
        }

        $owner = $this->owner;
        $live = Versioned::get_by_stage($owner->baseClass(), Versioned::LIVE)->byID($owner->ID);
        $this->purgeRecord($live, false, $owner->getAtVersion($fromStage));
    }

    public function onAfterUnpublish(): void
    {
        $this->purgeRecord(null, true);
    }

    public function onAfterArchive(): void
    {
        $this->purgeRecord(null, true);
    }

    /**
     * @param DataObject|null $original the Live record before this publish, if any
     * @param bool $removed the page left Live, so its listing and navigation entries go too
     * @param DataObject|null $incoming the record being published, when it is not the owner
     */
    protected function purgeRecord(?DataObject $original, bool $removed = false, ?DataObject $incoming = null): void
    {
        $queue = PurgeQueue::singleton();
        $owner = $this->owner;
        $incoming ??= $owner;

        if ($removed || $this->isStructuralChange($original, $incoming)) {
            $queue->addEverything();

            return;
        }

        $tags = array_merge([EdgeCache::pageTag($owner->ID)], EdgeCache::classChainTags(get_class($owner)));
        foreach ($this->ancestorIds() as $id) {
            $tags[] = EdgeCache::pageTag($id);
        }
        $queue->addTags($tags);
    }

    protected function isStructuralChange(?DataObject $original, DataObject $incoming): bool
    {
        // A page never live before is new to the navigation if it shows in menus.
        if (!$original || !$original->exists()) {
            return (bool) $incoming->ShowInMenus;
        }

        foreach ((array) Config::inst()->get(static::class, 'structural_fields') as $field) {
            if ($this->value($original, $field) !== $this->value($incoming, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A field's value as a string that compares equal however it got here. A Boolean read from the
     * database is `'0'` and the same field set in code is `false`.
     */
    protected function value(DataObject $record, string $field): string
    {
        $value = $record->$field;
        if ($record->dbObject($field) instanceof DBBoolean) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @return int[]
     */
    protected function ancestorIds(): array
    {
        $ids = [];
        $parent = $this->owner->Parent();
        while ($parent->exists()) {
            $ids[] = $parent->ID;
            $parent = $parent->Parent();
        }

        return $ids;
    }
}
