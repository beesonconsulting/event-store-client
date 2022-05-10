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

namespace Prooph\EventStoreClient\PersistentSubscriptions;

use Amp\Deferred;
use Amp\Http\Client\Response;
use Amp\Promise;
use JsonException;
use Prooph\EventStore\EndPoint;
use Prooph\EventStore\PersistentSubscriptions\PersistentSubscriptionDetails;
use Prooph\EventStore\Transport\Http\EndpointExtensions;
use Prooph\EventStore\Transport\Http\HttpStatusCode;
use Prooph\EventStore\UserCredentials;
use Prooph\EventStore\Util\Json;
use Prooph\EventStoreClient\Exception\PersistentSubscriptionCommandFailed;
use Prooph\EventStoreClient\Transport\Http\HttpClient;
use Throwable;
use UnexpectedValueException;

/** @internal */
class PersistentSubscriptionsClient
{
    private HttpClient $client;
    private string $httpSchema;

    public function __construct(int $operationTimeout, bool $tlsTerminatedEndpoint, bool $verifyPeer)
    {
        $this->client = new HttpClient($operationTimeout, $verifyPeer);
        $this->httpSchema = $tlsTerminatedEndpoint ? EndpointExtensions::HTTPS_SCHEMA : EndpointExtensions::HTTP_SCHEMA;
    }

    /**
     * @return Promise<PersistentSubscriptionDetails>
     */
    public function describe(
        EndPoint $endPoint,
        string $stream,
        string $subscriptionName,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/subscriptions/%s/%s/info',
                $stream,
                $subscriptionName
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $e, ?string $body) use ($deferred): void {
            if ($e) {
                $deferred->fail($e);

                return;
            }

            if (null === $body) {
                $deferred->fail(new UnexpectedValueException('No content received'));

                return;
            }

            try {
                $data = Json::decode($body);
            } catch (JsonException $e) {
                $deferred->fail($e);

                return;
            }

            $deferred->resolve(PersistentSubscriptionDetails::fromArray($data));
        });

        return $deferred->promise();
    }

    /**
     * @return Promise<PersistentSubscriptionDetails[]>
     */
    public function list(
        EndPoint $endPoint,
        ?string $stream = null,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $formatString = '/subscriptions';

        if (null !== $stream) {
            $formatString .= "/$stream";
        }

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                $formatString
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $e, ?string $body) use ($deferred): void {
            if ($e) {
                $deferred->fail($e);

                return;
            }

            if (null === $body) {
                $deferred->fail(new UnexpectedValueException('No content received'));

                return;
            }

            try {
                $data = Json::decode($body);
            } catch (JsonException $e) {
                $deferred->fail($e);

                return;
            }

            $details = [];

            foreach ($data as $entry) {
                $details[] = PersistentSubscriptionDetails::fromArray($entry);
            }

            $deferred->resolve($details);
        });

        return $deferred->promise();
    }

    /** @return Promise<void> */
    public function replayParkedMessages(
        EndPoint $endPoint,
        string $stream,
        string $subscriptionName,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/subscriptions/%s/%s/replayParked',
                $stream,
                $subscriptionName
            ),
            '',
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /**
     * @return Promise<string>
     */
    private function sendGet(string $url, ?UserCredentials $userCredentials, int $expectedCode): Promise
    {
        $deferred = new Deferred();

        $this->client->get(
            $url,
            $userCredentials,
            function (Response $response) use ($deferred, $expectedCode, $url): void {
                if ($response->getStatus() === $expectedCode) {
                    $deferred->resolve($response->getBody()->buffer());
                } else {
                    $deferred->fail(new PersistentSubscriptionCommandFailed(
                        $response->getStatus(),
                        \sprintf(
                            'Server returned %d (%s) for GET on %s',
                            $response->getStatus(),
                            $response->getReason(),
                            $url
                        )
                    ));
                }
            },
            function (Throwable $exception) use ($deferred): void {
                $deferred->fail($exception);
            }
        );

        return $deferred->promise();
    }

    /** @return Promise<void> */
    private function sendPost(
        string $url,
        string $content,
        ?UserCredentials $userCredentials,
        int $expectedCode
    ): Promise {
        $deferred = new Deferred();

        $this->client->post(
            $url,
            $content,
            'application/json',
            $userCredentials,
            function (Response $response) use ($deferred, $expectedCode, $url): void {
                if ($response->getStatus() === $expectedCode) {
                    $deferred->resolve(null);
                } else {
                    $deferred->fail(new PersistentSubscriptionCommandFailed(
                        $response->getStatus(),
                        \sprintf(
                            'Server returned %d (%s) for POST on %s',
                            $response->getStatus(),
                            $response->getReason(),
                            $url
                        )
                    ));
                }
            },
            function (Throwable $exception) use ($deferred): void {
                $deferred->fail($exception);
            }
        );

        return $deferred->promise();
    }
}
