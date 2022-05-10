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

namespace Prooph\EventStoreClient;

use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Prooph\EventStore\Async\EventStorePersistentSubscription;
use Prooph\EventStore\EndPoint;
use Prooph\EventStore\Exception\InvalidOperationException;
use Prooph\EventStore\PersistentSubscriptionSettings;
use Prooph\EventStore\ResolvedEvent;
use Prooph\EventStore\SubscriptionDropReason;
use Prooph\EventStore\UserCredentials;
use Throwable;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(function () {
    $connection = EventStoreConnectionFactory::createFromEndPoint(
        new EndPoint('eventstore', 1113)
    );

    $connection->onConnected(function (): void {
        echo 'connected' . PHP_EOL;
    });

    $connection->onClosed(function (): void {
        echo 'connection closed' . PHP_EOL;
    });

    yield $connection->connectAsync();

    try {
        $result = yield $connection->deletePersistentSubscriptionAsync(
            'foo-bar',
            'test-persistent-subscription',
            new UserCredentials('admin', 'changeit')
        );
        \var_dump($result);
    } catch (InvalidOperationException $exception) {
        echo 'no such subscription exists (yet)' . PHP_EOL;
    }

    $result = yield $connection->createPersistentSubscriptionAsync(
        'foo-bar',
        'test-persistent-subscription',
        PersistentSubscriptionSettings::default(),
        new UserCredentials('admin', 'changeit')
    );

    \var_dump($result);

    yield $connection->connectToPersistentSubscriptionAsync(
        'foo-bar',
        'test-persistent-subscription',
        function (
            EventStorePersistentSubscription $subscription,
            ResolvedEvent $resolvedEvent,
            ?int $retryCount = null
        ): Promise {
            echo 'incoming event: ' . $resolvedEvent->originalEventNumber() . '@' . $resolvedEvent->originalStreamName() . PHP_EOL;
            echo 'data: ' . $resolvedEvent->originalEvent()->data() . PHP_EOL;

            return new Success();
        },
        function (
            EventStorePersistentSubscription $subscription,
            SubscriptionDropReason $reason,
            ?Throwable $exception = null
        ): void {
            echo 'dropped with reason: ' . $reason->name() . PHP_EOL;

            if ($exception) {
                echo 'ex: ' . $exception->getMessage() . PHP_EOL;
            }
        },
        10,
        true,
        new UserCredentials('admin', 'changeit')
    );
});
