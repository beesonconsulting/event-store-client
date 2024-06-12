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

use Prooph\EventStore\EventStoreCatchUpSubscription;
use Prooph\EventStore\CatchUpSubscriptionSettings;
use Prooph\EventStore\EndPoint;
use Prooph\EventStore\ResolvedEvent;
use Prooph\EventStore\SubscriptionDropReason;
use Throwable;

require __DIR__ . '/../vendor/autoload.php';

$builder = new ConnectionSettingsBuilder();
$builder->enableVerboseLogging();
$builder->useConsoleLogger();

$connection = EventStoreConnectionFactory::createFromEndPoint(
    new EndPoint('eventstore', 1113),
    $builder->build()
);

$connection->onConnected(function (): void {
    echo 'connected' . PHP_EOL;
});

$connection->onClosed(function (): void {
    echo 'connection closed' . PHP_EOL;
});

$connection->connect();

$connection->subscribeToStreamFrom(
    'foo-bar',
    null,
    CatchUpSubscriptionSettings::default(),
    function (EventStoreCatchUpSubscription $subscription, ResolvedEvent $resolvedEvent): void {
        echo 'incoming event: ' . $resolvedEvent->originalEventNumber() . '@' . $resolvedEvent->originalStreamName() . PHP_EOL;
        echo 'data: ' . $resolvedEvent->originalEvent()->data() . PHP_EOL;
    },
    function (EventStoreCatchUpSubscription $subscription): void {
        echo 'liveProcessingStarted on ' . $subscription->streamId() . PHP_EOL;
    },
    function (
        EventStoreCatchUpSubscription $subscription,
        SubscriptionDropReason $reason,
        ?Throwable $exception = null
    ): void {
        echo 'dropped with reason: ' . $reason->name . PHP_EOL;

        if ($exception) {
            echo 'ex: ' . $exception->getMessage() . PHP_EOL;
        }
    }
);
