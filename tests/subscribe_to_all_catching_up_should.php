<?php

/**
 * This file is part of `prooph/event-store-client`.
 * (c) 2018-2022 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2018-2022 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\EventStoreClient;

use Amp\Deferred;
use Amp\Delayed;
use Amp\Promise;
use Amp\Success;
use Exception;
use Generator;
use Prooph\EventStore\AllEventsSlice;
use Prooph\EventStore\Async\EventStoreCatchUpSubscription;
use Prooph\EventStore\CatchUpSubscriptionSettings;
use Prooph\EventStore\Common\SystemRoles;
use Prooph\EventStore\Common\SystemStreams;
use Prooph\EventStore\EventData;
use Prooph\EventStore\EventStoreSubscription;
use Prooph\EventStore\ExpectedVersion;
use Prooph\EventStore\Position;
use Prooph\EventStore\ResolvedEvent;
use Prooph\EventStore\StreamMetadata;
use Prooph\EventStore\SubscriptionDropReason;
use Prooph\EventStore\UserCredentials;
use Prooph\EventStoreClient\Internal\EventStoreAllCatchUpSubscription;
use ProophTest\EventStoreClient\Helper\TestEvent;
use Throwable;

class subscribe_to_all_catching_up_should extends EventStoreConnectionTestCase
{
    private const TIMEOUT = 10000;

    protected function setUpAsync(): Generator
    {
        yield from parent::setUpAsync();

        yield $this->connection->setStreamMetadataAsync(
            '$all',
            ExpectedVersion::ANY,
            StreamMetadata::create()->setReadRoles(SystemRoles::ALL)->build(),
            new UserCredentials(SystemUsers::ADMIN, SystemUsers::DEFAULT_ADMIN_PASSWORD)
        );
    }

    protected function tearDownAsync(): Generator
    {
        yield $this->connection->setStreamMetadataAsync(
            '$all',
            ExpectedVersion::ANY,
            StreamMetadata::create()->build(),
            new UserCredentials(SystemUsers::ADMIN, SystemUsers::DEFAULT_ADMIN_PASSWORD)
        );

        yield from parent::tearDownAsync();
    }

    /** @test */
    public function call_dropped_callback_after_stop_method_call(): Generator
    {
        $dropped = new CountdownEvent(1);

        $subscription = yield $this->connection->subscribeToAllFromAsync(
            null,
            CatchUpSubscriptionSettings::default(),
            function (
                EventStoreCatchUpSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ): Promise {
                return new Success();
            },
            null,
            function (
                EventStoreCatchUpSubscription $subscription,
                SubscriptionDropReason $reason,
                ?Throwable $exception = null
            ) use ($dropped): void {
                $dropped->signal();
            }
        );
        \assert($subscription instanceof EventStoreAllCatchUpSubscription);

        $this->assertFalse(yield $dropped->wait(0));
        yield $subscription->stop(self::TIMEOUT);
        $this->assertTrue(yield $dropped->wait(self::TIMEOUT));
    }

    /** @test */
    public function call_dropped_callback_when_an_error_occurs_while_processing_an_event(): Generator
    {
        $stream = 'all_call_dropped_callback_when_an_error_occurs_while_processing_an_event';

        yield $this->connection->appendToStreamAsync(
            $stream,
            ExpectedVersion::ANY,
            [TestEvent::newTestEvent()]
        );

        $dropped = new CountdownEvent(1);

        $subscription = yield $this->connection->subscribeToAllFromAsync(
            null,
            CatchUpSubscriptionSettings::default(),
            function (
                EventStoreCatchUpSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ): Promise {
                throw new Exception('Error');
            },
            null,
            function (
                EventStoreCatchUpSubscription $subscription,
                SubscriptionDropReason $reason,
                ?Throwable $exception = null
            ) use ($dropped): void {
                $dropped->signal();
            }
        );
        \assert($subscription instanceof EventStoreAllCatchUpSubscription);

        yield $subscription->stop(self::TIMEOUT);

        $this->assertTrue(yield $dropped->wait(self::TIMEOUT));
    }

    /**
     * No way to guarantee an empty db
     *
     * @test
     * @group ignore
     */
    public function be_able_to_subscribe_to_empty_db(): Generator
    {
        $appeared = new Deferred();
        $dropped = new CountdownEvent(1);

        $subscription = yield $this->connection->subscribeToAllFromAsync(
            null,
            CatchUpSubscriptionSettings::default(),
            function (
                EventStoreCatchUpSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ) use ($appeared): Promise {
                if (! SystemStreams::isSystemStream($resolvedEvent->originalEvent()->eventStreamId())) {
                    $appeared->resolve(true);
                }

                return new Success();
            },
            null,
            function (
                EventStoreCatchUpSubscription $subscription,
                SubscriptionDropReason $reason,
                ?Throwable $exception = null
            ) use ($dropped): void {
                $dropped->signal();
            }
        );
        \assert($subscription instanceof EventStoreAllCatchUpSubscription);

        yield new Delayed(5000); // give time for first pull phase

        yield $this->connection->subscribeToAllAsync(
            false,
            function (
                EventStoreSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ): Promise {
                return new Success();
            }
        );

        yield new Delayed(5000);

        $this->assertFalse(yield Promise\timeoutWithDefault($appeared->promise(), 0, false), 'Some event appeared');
        $this->assertFalse(yield $dropped->wait(0), 'Subscription was dropped prematurely');

        yield $subscription->stop(self::TIMEOUT);

        $this->assertTrue(yield $dropped->wait(self::TIMEOUT));
    }

    /** @test */
    public function read_all_existing_events_and_keep_listening_to_new_ones(): Generator
    {
        $result = yield $this->connection->readAllEventsBackwardAsync(Position::end(), 1, false);
        \assert($result instanceof AllEventsSlice);
        $position = $result->nextPosition();

        $events = [];
        $appeared = new CountdownEvent(20);
        $dropped = new CountdownEvent(1);

        for ($i = 0; $i < 10; $i++) {
            yield $this->connection->appendToStreamAsync(
                'all_read_all_existing_events_and_keep_listening_to_new_ones-' . $i,
                -1,
                [new EventData(null, 'et-' . $i, false)]
            );
        }

        $subscription = yield $this->connection->subscribeToAllFromAsync(
            $position,
            CatchUpSubscriptionSettings::default(),
            function (
                EventStoreCatchUpSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ) use (&$events, $appeared): Promise {
                if (! SystemStreams::isSystemStream($resolvedEvent->originalEvent()->eventStreamId())) {
                    $events[] = $resolvedEvent;
                    $appeared->signal();
                }

                return new Success();
            },
            null,
            function (
                EventStoreCatchUpSubscription $subscription,
                SubscriptionDropReason $reason,
                ?Throwable $exception = null
            ) use ($dropped): void {
                $dropped->signal();
            }
        );
        \assert($subscription instanceof EventStoreAllCatchUpSubscription);

        for ($i = 10; $i < 20; $i++) {
            yield $this->connection->appendToStreamAsync(
                'all_read_all_existing_events_and_keep_listening_to_new_ones-' . $i,
                -1,
                [new EventData(null, 'et-' . $i, false)]
            );
        }

        if (! yield $appeared->wait(self::TIMEOUT)) {
            $this->assertFalse(yield $dropped->wait(0), 'Subscription was dropped prematurely');
            $this->fail('Could not wait for all events');

            return;
        }

        $this->assertCount(20, $events);

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('et-' . $i, $events[$i]->originalEvent()->eventType());
        }

        $this->assertFalse(yield $dropped->wait(0));
        yield $subscription->stop(self::TIMEOUT);
        $this->assertTrue(yield $dropped->wait(self::TIMEOUT));
    }

    /**
     * Not working against single db
     *
     * @test
     * @group ignore
     */
    public function filter_events_and_keep_listening_to_new_ones(): Generator
    {
        $events = [];
        $appeared = new CountdownEvent(10);
        $dropped = new CountdownEvent(1);

        for ($i = 0; $i < 10; $i++) {
            yield $this->connection->appendToStreamAsync(
                'all_filter_events_and_keep_listening_to_new_ones-' . $i,
                -1,
                [new EventData(null, 'et-' . $i, false)]
            );
        }

        $allSlice = yield $this->connection->readAllEventsForwardAsync(Position::start(), 100, false);
        \assert($allSlice instanceof AllEventsSlice);
        $lastEvent = \array_values(\array_slice($allSlice->events(), -1))[0];
        \assert($lastEvent instanceof ResolvedEvent);

        $subscription = yield $this->connection->subscribeToAllFromAsync(
            $lastEvent->originalPosition(),
            CatchUpSubscriptionSettings::default(),
            function (
                EventStoreCatchUpSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ) use (&$events, $appeared): Promise {
                if (! SystemStreams::isSystemStream($resolvedEvent->originalEvent()->eventStreamId())) {
                    $events[] = $resolvedEvent;
                    $appeared->signal();
                }

                return new Success();
            },
            null,
            function (
                EventStoreCatchUpSubscription $subscription,
                SubscriptionDropReason $reason,
                ?Throwable $exception = null
            ) use ($dropped): void {
                $dropped->signal();
            }
        );
        \assert($subscription instanceof EventStoreAllCatchUpSubscription);

        for ($i = 10; $i < 20; $i++) {
            yield $this->connection->appendToStreamAsync(
                'all_filter_events_and_keep_listening_to_new_ones-' . $i,
                -1,
                [new EventData(null, 'et-' . $i, false)]
            );
        }

        if (! yield $appeared->wait(self::TIMEOUT)) {
            $this->assertFalse(yield $dropped->wait(0), 'Subscription was dropped prematurely');
            $this->fail('Could not wait for all events');

            return;
        }

        $this->assertCount(10, $events);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('et-' . (10 + $i), $events[$i]->originalEvent()->eventType());
        }

        $this->assertFalse(yield $dropped->wait(0));
        yield $subscription->stop();
        $this->assertTrue(yield $dropped->wait(self::TIMEOUT));

        $lastEvent = \array_values(\array_slice($events, -1))[0];
        \assert($lastEvent instanceof ResolvedEvent);
        $this->assertTrue($lastEvent->originalPosition()->equals($subscription->lastProcessedPosition()));
    }

    /**
     * Not working against single db
     *
     * @test
     * @group ignore
     */
    public function filter_events_and_work_if_nothing_was_written_after_subscription(): Generator
    {
        /** @var ResolvedEvent[] $events */
        $events = [];
        $appeared = new CountdownEvent(1);
        $dropped = new CountdownEvent(1);

        for ($i = 0; $i < 10; $i++) {
            yield $this->connection->appendToStreamAsync(
                'all_filter_events_and_work_if_nothing_was_written_after_subscription-' . $i,
                -1,
                [new EventData(null, 'et-' . $i, false)]
            );
        }

        $allSlice = yield $this->connection->readAllEventsBackwardAsync(Position::end(), 2, false);
        \assert($allSlice instanceof AllEventsSlice);
        $lastEvent = $allSlice->events()[1];

        $subscription = yield $this->connection->subscribeToAllFromAsync(
            $lastEvent->originalPosition(),
            CatchUpSubscriptionSettings::default(),
            function (
                EventStoreCatchUpSubscription $subscription,
                ResolvedEvent $resolvedEvent
            ) use (&$events, $appeared): Promise {
                $events[] = $resolvedEvent;
                $appeared->signal();

                return new Success();
            },
            null,
            function (
                EventStoreCatchUpSubscription $subscription,
                SubscriptionDropReason $reason,
                ?Throwable $exception = null
            ) use ($dropped): void {
                $dropped->signal();
            }
        );
        \assert($subscription instanceof EventStoreAllCatchUpSubscription);

        if (! yield $appeared->wait(self::TIMEOUT)) {
            $this->assertFalse(yield $dropped->wait(0), 'Subscription was dropped prematurely');
            $this->fail('Could not wait for all events');

            return;
        }

        $this->assertCount(1, $events);
        $this->assertSame('et-9', $events[0]->originalEvent()->eventType());

        $this->assertFalse(yield $dropped->wait(0));
        yield $subscription->stop(self::TIMEOUT);
        $this->assertTrue(yield $dropped->wait(self::TIMEOUT));

        $this->assertTrue($events[0]->originalPosition()->equals($subscription->lastProcessedPosition()));
    }
}
