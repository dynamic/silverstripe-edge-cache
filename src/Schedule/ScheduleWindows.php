<?php

namespace Dynamic\EdgeCache\Schedule;

use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Finds the next moment a scheduled record starts or ends showing, so a page that lists such
 * records is not kept at the edge past it. A publish cannot purge at the instant a banner's start
 * time arrives, so the edge lifetime has to run out by then instead.
 *
 * A class names its date fields in config:
 *
 *     Vendor\Notifications\Model\PopUp:
 *       edge_cache_schedule_fields:
 *         - StartTime
 *         - EndTime
 *
 * Both a start and an end count: one makes a record appear, the other makes it disappear. The
 * earliest future value across every record of each class the page showed wins; whether a given
 * record is on the page does not matter, only that a boundary is coming.
 */
class ScheduleWindows
{
    use Injectable;

    /**
     * @param string[] $classes the classes the page showed
     *
     * @return int|null seconds until the next boundary, null when no scheduled field is configured
     *                  or none lies ahead
     *
     * @throws InvalidArgumentException when a configured field is not a database field of its class
     */
    public function secondsUntilNextChange(array $classes): ?int
    {
        $now = DBDatetime::now();
        $nowValue = $now->getValue();
        $nextTimestamp = null;

        foreach ($this->fields($classes) as [$class, $field]) {
            $next = DataObject::get($class)->filter($field . ':GreaterThan', $nowValue)->min($field);
            if ($next === null || $next === '') {
                continue;
            }

            $timestamp = DBField::create_field(DBDatetime::class, $next)->getTimestamp();
            $nextTimestamp = $nextTimestamp === null ? $timestamp : min($nextTimestamp, $timestamp);
        }

        return $nextTimestamp === null ? null : max(0, $nextTimestamp - $now->getTimestamp());
    }

    /**
     * @param string[] $classes
     *
     * @return array<int, array{string, string}> [class, field] pairs, each once
     */
    protected function fields(array $classes): array
    {
        $pairs = [];
        foreach ($classes as $class) {
            if (!is_subclass_of($class, DataObject::class)) {
                continue;
            }
            foreach ((array) Config::inst()->get($class, 'edge_cache_schedule_fields') as $field) {
                if (!DataObject::getSchema()->fieldSpec($class, $field)) {
                    throw new InvalidArgumentException(
                        sprintf('edge_cache_schedule_fields on %s names %s, which is not one of its fields.', $class, $field)
                    );
                }
                $pairs[$class . '::' . $field] = [$class, $field];
            }
        }

        return array_values($pairs);
    }
}
