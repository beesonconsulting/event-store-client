<?php

/**
 * This file is part of `prooph/event-store-client`.
 * (c) 2018-2024 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2018-2024 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\EventStoreClient;

use Amp\PHPUnit\AsyncTestCase;
use Prooph\EventStore\EventData;
use Prooph\EventStore\ExpectedVersion;
use Prooph\EventStore\PersistentSubscriptionSettings;
use Prooph\EventStore\Util\Guid;

class create_persistent_subscription_on_existing_stream extends AsyncTestCase
{
    use SpecificationWithConnection;

    private string $stream;

    private PersistentSubscriptionSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stream = Guid::generateAsHex();
        $this->settings = PersistentSubscriptionSettings::create()
            ->doNotResolveLinkTos()
            ->startFromCurrent()
            ->build();
    }

    protected function when(): void
    {
        $this->connection->appendToStream(
            $this->stream,
            ExpectedVersion::Any,
            [new EventData(null, 'whatever', true, '{"foo":"bar"}')]
        );
    }

    /**
     * @test
     * @doesNotPerformAssertions
     */
    public function the_completion_succeeds(): void
    {
        $this->execute(function (): void {
            $this->connection->createPersistentSubscription(
                $this->stream,
                'existing',
                $this->settings,
                DefaultData::adminCredentials()
            );
        });
    }
}
