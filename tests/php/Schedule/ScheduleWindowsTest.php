<?php

namespace Dynamic\EdgeCache\Tests\Schedule;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Middleware\EdgeCacheMiddleware;
use Dynamic\EdgeCache\Schedule\ScheduleWindows;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ListedThing;
use Dynamic\EdgeCache\Tests\Fixtures\MisconfiguredScheduleThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledDayThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledSubThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledThing;
use InvalidArgumentException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * A publish cannot purge at the moment a scheduled banner starts or ends, so the edge lifetime of
 * a page that lists such records is cut to the time left until the next boundary.
 */
class ScheduleWindowsTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [
        ListedThing::class,
        ScheduledThing::class,
        ScheduledSubThing::class,
        ScheduledDayThing::class,
        MisconfiguredScheduleThing::class,
    ];

    private const NOW = '2026-10-07 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now(self::NOW);
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function thing(string $start, string $end = '', string $class = ScheduledThing::class): void
    {
        $class::create(['Title' => 'Banner', 'StartTime' => $start, 'EndTime' => $end ?: null])->write();
    }

    private function seconds(array $classes): ?int
    {
        return ScheduleWindows::singleton()->secondsUntilNextChange($classes);
    }

    /**
     * Render a page that runs $render while the middleware records its queries.
     */
    private function page(callable $render): HTTPResponse
    {
        $response = new HTTPResponse('body', 200);
        $response->addHeader('Cache-Control', 'public, max-age=60, must-revalidate');

        return (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'about'), function () use ($render, $response) {
            EdgeCache::singleton()->markCacheable();
            $render();

            return $response;
        });
    }

    public function testTheNearestFutureStartOrEndWins(): void
    {
        $this->thing('2026-10-07 11:00:00', '2026-10-07 13:00:00');
        $this->thing('2026-10-07 12:10:00', '2026-10-07 14:00:00');

        $this->assertSame(600, $this->seconds([ScheduledThing::class]));
    }

    public function testAnEndTimeCountsAsMuchAsAStartTime(): void
    {
        $this->thing('2026-10-07 09:00:00', '2026-10-07 12:30:00');

        $this->assertSame(1800, $this->seconds([ScheduledThing::class]));
    }

    public function testNothingAheadMeansNoCap(): void
    {
        $this->thing('2026-10-07 09:00:00', '2026-10-07 11:59:59');

        $this->assertNull($this->seconds([ScheduledThing::class]));
    }

    public function testAClassWithoutScheduledFieldsIsNotConsulted(): void
    {
        $this->assertNull($this->seconds([ListedThing::class, 'Not\\A\\Class']));
    }

    public function testASubclassInheritsTheScheduledFields(): void
    {
        $this->thing('2026-10-07 12:05:00', '', ScheduledSubThing::class);

        $this->assertSame(300, $this->seconds([ScheduledSubThing::class]));
        $this->assertSame(300, $this->seconds([ScheduledThing::class]), 'the parent query includes subclass rows');
    }

    public function testADateFieldChangesAtMidnight(): void
    {
        ScheduledDayThing::create(['Title' => 'Tomorrow', 'PublishOn' => '2026-10-08'])->write();
        ScheduledDayThing::create(['Title' => 'Today', 'PublishOn' => '2026-10-07'])->write();

        $this->assertSame(12 * 3600, $this->seconds([ScheduledDayThing::class]));
    }

    public function testAFieldTheClassDoesNotHaveIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NoSuchField');

        $this->seconds([MisconfiguredScheduleThing::class]);
    }

    public function testThePageIsCachedOnlyUntilTheNextBoundary(): void
    {
        $this->thing('2026-10-07 12:10:00');

        $response = $this->page(fn () => ScheduledThing::get()->toArray());

        $this->assertSame('max-age=600', $response->getHeader('Edge-Cache-Control'));
    }

    public function testTheCapNeverRaisesTheConfiguredLifetime(): void
    {
        $this->thing('2026-10-09 12:00:00');

        $response = $this->page(fn () => ScheduledThing::get()->toArray());

        $this->assertSame('max-age=21600', $response->getHeader('Edge-Cache-Control'));
    }

    public function testABoundaryInsideTheFloorUsesTheFloor(): void
    {
        $this->thing('2026-10-07 12:00:20');

        $response = $this->page(fn () => ScheduledThing::get()->toArray());

        $this->assertSame('max-age=60', $response->getHeader('Edge-Cache-Control'));
    }

    public function testAPageThatDoesNotListScheduledRecordsKeepsItsLifetime(): void
    {
        $this->thing('2026-10-07 12:10:00');

        $response = $this->page(fn () => ListedThing::get()->toArray());

        $this->assertSame('max-age=21600', $response->getHeader('Edge-Cache-Control'));
    }

    public function testADeclaredDependencyCountsWithoutAQuery(): void
    {
        $this->thing('2026-10-07 12:10:00');

        $response = $this->page(fn () => EdgeCache::singleton()->declareClass(ScheduledThing::class));

        $this->assertSame('max-age=600', $response->getHeader('Edge-Cache-Control'));
    }

    public function testAMisconfiguredFieldStillCachesForTheShortestLifetime(): void
    {
        $response = $this->page(fn () => MisconfiguredScheduleThing::get()->toArray());

        $this->assertSame('max-age=60', $response->getHeader('Edge-Cache-Control'));
    }

    public function testTheCapIsForgottenBetweenRequests(): void
    {
        $edge = EdgeCache::singleton();
        $edge->capEdgeTtl(120);
        $edge->reset();

        $this->assertSame(21600, $edge->policy()->getEdgeTtl());
    }

    public function testTheShortestCapWins(): void
    {
        $edge = EdgeCache::singleton();
        $edge->capEdgeTtl(900);
        $edge->capEdgeTtl(300);
        $edge->capEdgeTtl(600);

        $this->assertSame(300, $edge->policy()->getEdgeTtl());
    }
}
