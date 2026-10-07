<?php

namespace Dynamic\EdgeCache\Tests\Schedule;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Middleware\EdgeCacheMiddleware;
use Dynamic\EdgeCache\Schedule\ScheduleWindows;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Extension\EdgeCacheElementExtension;
use Dynamic\EdgeCache\Tests\Fixtures\ListedThing;
use Dynamic\EdgeCache\Tests\Fixtures\MisconfiguredScheduleThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledChildThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledDayThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledElement;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledParentThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledSubThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledThing;
use Dynamic\EdgeCache\Tests\Fixtures\ScheduledVersionedThing;
use Dynamic\EdgeCache\Tests\Fixtures\TextScheduleThing;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;

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
        TextScheduleThing::class,
        ScheduledParentThing::class,
        ScheduledChildThing::class,
        ScheduledVersionedThing::class,
        ScheduledElement::class,
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

    public function testAMisconfiguredFieldStillCachesForTheShortestLifetimeAndSaysSo(): void
    {
        $handler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$handler]), LoggerInterface::class);
        EdgeCache::config()->set('schedule_ttl_floor', 90);

        $response = $this->page(fn () => MisconfiguredScheduleThing::get()->toArray());

        $this->assertSame('max-age=90', $response->getHeader('Edge-Cache-Control'));
        $records = array_filter($handler->getRecords(), fn ($r) => str_contains($r['message'], 'shortest lifetime'));
        $this->assertCount(1, $records);
        $this->assertStringContainsString('NoSuchField', array_values($records)[0]['message']);
    }

    public function testAFieldThatIsNotADateIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date or Datetime');

        $this->seconds([TextScheduleThing::class]);
    }

    public function testAClassWhoseTagWasAlreadyOnThePageStillCounts(): void
    {
        $this->thing('2026-10-07 12:10:00');

        // Declared by an element or hook as a plain tag, then queried: the query adds no tag.
        $response = $this->page(function () {
            EdgeCache::singleton()->addTags(EdgeCache::classTag(ScheduledThing::class));
            ScheduledThing::get()->toArray();
        });

        $this->assertSame('max-age=600', $response->getHeader('Edge-Cache-Control'));
    }

    public function testScheduledFieldsOnlyASubclassConfiguresAreFoundThroughTheParent(): void
    {
        ScheduledChildThing::create(['Title' => 'Timed', 'ExpiresAt' => '2026-10-07 12:20:00'])->write();

        $response = $this->page(fn () => ScheduledParentThing::get()->toArray());

        $this->assertSame('max-age=1200', $response->getHeader('Edge-Cache-Control'));
    }

    public function testAnInclusiveEndDateChangesAtTheNextMidnight(): void
    {
        // Shows through the 7th: it is gone from midnight on the 8th, 12 hours from now.
        ScheduledDayThing::create(['Title' => 'Today', 'PublishOn' => '2026-10-07'])->write();

        $this->assertSame(12 * 3600, $this->seconds([ScheduledDayThing::class]));
    }

    public function testADraftOnlyRecordDoesNotCapALivePage(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        ScheduledVersionedThing::create(['Title' => 'Draft', 'StartTime' => '2026-10-07 12:05:00'])->write();
        $published = ScheduledVersionedThing::create(['Title' => 'Live', 'StartTime' => '2026-10-07 12:10:00']);
        $published->write();
        $published->publishSingle();
        Versioned::set_stage(Versioned::LIVE);

        $this->assertSame(600, $this->seconds([ScheduledVersionedThing::class]));
    }

    public function testAnElementDeclaresItselfAndItsDependencies(): void
    {
        $element = ScheduledElement::create(['Title' => 'Banner']);
        $edge = EdgeCache::singleton();

        $element->getExtensionInstance(EdgeCacheElementExtension::class)->setOwner($element);
        $element->edgeCacheTags();

        $this->assertEqualsCanonicalizing(
            [ScheduledElement::class, ScheduledThing::class],
            $edge->collectedClasses()
        );
    }

    public function testResetForgetsEveryCollectedClass(): void
    {
        $edge = EdgeCache::singleton();
        $edge->declareClass(ScheduledThing::class);
        $edge->collectClass(ScheduledDayThing::class);
        $edge->addTags(EdgeCache::classTag(ScheduledSubThing::class));
        $edge->collectClass(ScheduledSubThing::class);
        $this->assertNotSame([], $edge->collectedClasses());

        $edge->reset();

        $this->assertSame([], $edge->collectedClasses());
    }

    public function testAPagesOwnLazyLoadIsNotACollectedClassButAnotherRecordsIs(): void
    {
        $edge = EdgeCache::singleton();
        $edge->setCurrentPageId(5);

        $edge->collectLazyClass(ScheduledThing::class, 5);
        $this->assertSame([], $edge->collectedClasses());

        $edge->collectLazyClass(ScheduledThing::class, 9);
        $this->assertSame([ScheduledThing::class], $edge->collectedClasses());
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
