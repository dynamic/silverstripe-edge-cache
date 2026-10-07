<?php

namespace Dynamic\EdgeCache\Schedule;

use InvalidArgumentException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
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
 * record is on the page does not matter, only that a boundary is coming. A Date (no time) is a
 * boundary at the start of that day and again at the start of the next, so a start date and an
 * inclusive end date are both caught.
 */
class ScheduleWindows
{
    use Injectable;

    private const DAY = 86400;

    /**
     * @param string[] $classes the classes the page showed
     *
     * @return int|null seconds until the next boundary, null when no scheduled field is configured
     *                  or none lies ahead
     *
     * @throws InvalidArgumentException when a configured field is not a date field of its class
     */
    public function secondsUntilNextChange(array $classes): ?int
    {
        $now = DBDatetime::now();
        $nowTimestamp = $now->getTimestamp();
        $next = null;

        foreach ($this->fields($classes) as [$class, $field, $dateOnly]) {
            $boundary = $this->nextBoundary($class, $field, $dateOnly, $now->getValue(), $nowTimestamp);
            if ($boundary !== null) {
                $next = $next === null ? $boundary : min($next, $boundary);
            }
        }

        return $next === null ? null : max(0, $next - $nowTimestamp);
    }

    /**
     * @return int|null timestamp of the earliest boundary after now, if any
     */
    protected function nextBoundary(string $class, string $field, bool $dateOnly, string $now, int $nowTimestamp): ?int
    {
        // A Date is a boundary at its own midnight and the next one, so look back a day.
        $after = $dateOnly ? date('Y-m-d H:i:s', $nowTimestamp - self::DAY) : $now;
        $value = DataObject::get($class)->filter($field . ':GreaterThan', $after)->min($field);
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = DBField::create_field(DBDatetime::class, $value)->getTimestamp();

        return $dateOnly && $timestamp <= $nowTimestamp ? $timestamp + self::DAY : $timestamp;
    }

    /**
     * Each class's own fields, and those only a subclass configures (a page that lists a base class
     * gets subclass rows back).
     *
     * @param string[] $classes
     *
     * @return array<int, array{string, string, bool}> [class, field, date only] each once
     */
    protected function fields(array $classes): array
    {
        $pairs = [];
        foreach ($classes as $class) {
            if (!is_subclass_of($class, DataObject::class)) {
                continue;
            }

            $own = $this->configured($class);
            foreach ($own as $field) {
                $pairs[$class . '::' . $field] = [$class, $field, $this->isDateOnly($class, $field)];
            }
            foreach (ClassInfo::subclassesFor($class, false) as $sub) {
                foreach (array_diff($this->configured($sub), $own) as $field) {
                    $pairs[$sub . '::' . $field] = [$sub, $field, $this->isDateOnly($sub, $field)];
                }
            }
        }

        return array_values($pairs);
    }

    /**
     * @return string[]
     */
    protected function configured(string $class): array
    {
        return (array) Config::inst()->get($class, 'edge_cache_schedule_fields');
    }

    /**
     * @throws InvalidArgumentException when the class has no such date field
     */
    protected function isDateOnly(string $class, string $field): bool
    {
        $object = DataObject::getSchema()->fieldSpec($class, $field)
            ? DataObject::singleton($class)->dbObject($field)
            : null;
        if (!$object instanceof DBDate) {
            throw new InvalidArgumentException(sprintf(
                'edge_cache_schedule_fields on %s names %s, which is not one of its Date or Datetime fields.',
                $class,
                $field
            ));
        }

        return !$object instanceof DBDatetime;
    }
}
