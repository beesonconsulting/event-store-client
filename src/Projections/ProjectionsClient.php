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

namespace Prooph\EventStoreClient\Projections;

use Amp\Deferred;
use Amp\Http\Client\Response;
use Amp\Promise;
use JsonException;
use Prooph\EventStore\EndPoint;
use Prooph\EventStore\Projections\ProjectionDetails;
use Prooph\EventStore\Projections\ProjectionStatistics;
use Prooph\EventStore\Projections\Query;
use Prooph\EventStore\Projections\State;
use Prooph\EventStore\Transport\Http\EndpointExtensions;
use Prooph\EventStore\Transport\Http\HttpStatusCode;
use Prooph\EventStore\UserCredentials;
use Prooph\EventStore\Util\Json;
use Prooph\EventStoreClient\Exception\ProjectionCommandConflict;
use Prooph\EventStoreClient\Exception\ProjectionCommandFailed;
use Prooph\EventStoreClient\Transport\Http\HttpClient;
use Throwable;
use UnexpectedValueException;

/** @internal */
class ProjectionsClient
{
    private HttpClient $client;
    private int $operationTimeout;
    private string $httpSchema;

    public function __construct(int $operationTimeout, bool $tlsTerminatedEndpoint, bool $verifyPeer)
    {
        $this->client = new HttpClient($operationTimeout, $verifyPeer);
        $this->operationTimeout = $operationTimeout;
        $this->httpSchema = $tlsTerminatedEndpoint ? EndpointExtensions::HTTPS_SCHEMA : EndpointExtensions::HTTP_SCHEMA;
    }

    /** @return Promise<void> */
    public function enable(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/command/enable',
                \urlencode($name)
            ),
            '',
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /** @return Promise<void> */
    public function disable(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/command/disable',
                \urlencode($name)
            ),
            '',
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /** @return Promise<void> */
    public function abort(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/command/abort',
                \urlencode($name)
            ),
            '',
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /** @return Promise<void> */
    public function createOneTime(
        EndPoint $endPoint,
        string $query,
        string $type,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projections/onetime?type=%s',
                $type
            ),
            $query,
            $userCredentials,
            HttpStatusCode::CREATED
        );
    }

    /** @return Promise<void> */
    public function createTransient(
        EndPoint $endPoint,
        string $name,
        string $query,
        string $type,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projections/transient?name=%s&type=%s',
                \urlencode($name),
                $type
            ),
            $query,
            $userCredentials,
            HttpStatusCode::CREATED
        );
    }

    /** @return Promise<void> */
    public function createContinuous(
        EndPoint $endPoint,
        string $name,
        string $query,
        bool $trackEmittedStreams,
        string $type,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projections/continuous?name=%s&type=%s&emit=1&trackemittedstreams=%d',
                \urlencode($name),
                $type,
                (string) (int) $trackEmittedStreams
            ),
            $query,
            $userCredentials,
            HttpStatusCode::CREATED
        );
    }

    /** @return Promise<list<ProjectionDetails>> */
    public function listAll(
        EndPoint $endPoint,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::rawUrlToHttpUrl($endPoint, $this->httpSchema, '/projections/any'),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

                return;
            }

            if (null === $body) {
                $deferred->fail(new \UnexpectedValueException('Body cannot be empty'));

                return;
            }

            try {
                $data = Json::decode($body);
            } catch (JsonException $e) {
                $deferred->fail($e);

                return;
            }

            $projectionDetails = [];

            if (null === $data['projections']) {
                $deferred->resolve($projectionDetails);

                return;
            }

            foreach ($data['projections'] as $entry) {
                $projectionDetails[] = $this->buildProjectionDetails($entry);
            }

            $deferred->resolve($projectionDetails);
        });

        return $deferred->promise();
    }

    /** @return Promise<list<ProjectionDetails>> */
    public function listOneTime(
        EndPoint $endPoint,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::rawUrlToHttpUrl($endPoint, $this->httpSchema, '/projections/onetime'),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

                return;
            }

            if (null === $body) {
                $deferred->fail(new \UnexpectedValueException('Body cannot be empty'));

                return;
            }

            try {
                $data = Json::decode($body);
            } catch (JsonException $e) {
                $deferred->fail($e);

                return;
            }

            $projectionDetails = [];

            if (null === $data['projections']) {
                $deferred->resolve($projectionDetails);

                return;
            }

            foreach ($data['projections'] as $entry) {
                $projectionDetails[] = $this->buildProjectionDetails($entry);
            }

            $deferred->resolve($projectionDetails);
        });

        return $deferred->promise();
    }

    /** @return Promise<list<ProjectionDetails>> */
    public function listContinuous(
        EndPoint $endPoint,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::rawUrlToHttpUrl($endPoint, $this->httpSchema, '/projections/continuous'),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

                return;
            }

            if (null === $body) {
                $deferred->fail(new \UnexpectedValueException('Body cannot be empty'));

                return;
            }

            try {
                $data = Json::decode($body);
            } catch (JsonException $e) {
                $deferred->fail($e);

                return;
            }

            $projectionDetails = [];

            if (null === $data['projections']) {
                $deferred->resolve($projectionDetails);

                return;
            }

            foreach ($data['projections'] as $entry) {
                $projectionDetails[] = $this->buildProjectionDetails($entry);
            }

            $deferred->resolve($projectionDetails);
        });

        return $deferred->promise();
    }

    /** @return Promise<ProjectionDetails> */
    public function getStatus(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s',
                $name
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

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

            $projectionDetails = $this->buildProjectionDetails($data);
            $deferred->resolve($projectionDetails);
        });

        return $deferred->promise();
    }

    /** @return Promise<State> */
    public function getState(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/state',
                $name
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

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

            $deferred->resolve(new State($data));
        });

        return $deferred->promise();
    }

    /** @return Promise<State> */
    public function getPartitionState(
        EndPoint $endPoint,
        string $name,
        string $partition,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/state?partition=%s',
                $name,
                $partition
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

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

            $deferred->resolve(new State($data));
        });

        return $deferred->promise();
    }

    /** @return Promise<State> */
    public function getResult(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/result',
                $name
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

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

            $deferred->resolve(new State($data));
        });

        return $deferred->promise();
    }

    /** @return Promise<State> */
    public function getPartitionResult(
        EndPoint $endPoint,
        string $name,
        string $partition,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/result?partition=%s',
                $name,
                $partition
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

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

            $deferred->resolve(new State($data));
        });

        return $deferred->promise();
    }

    /** @return Promise<ProjectionStatistics> */
    public function getStatistics(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/statistics',
                $name
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

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

            $projectionStatistics = $this->buildProjectionStatistics($data);
            $deferred->resolve($projectionStatistics);
        });

        return $deferred->promise();
    }

    /** @return Promise<Query> */
    public function getQuery(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $deferred = new Deferred();

        $promise = $this->sendGet(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/query',
                $name
            ),
            $userCredentials,
            HttpStatusCode::OK
        );

        $promise->onResolve(function (?Throwable $exception, ?string $body) use ($deferred): void {
            if ($exception) {
                $deferred->fail($exception);

                return;
            }

            if (null === $body) {
                $deferred->fail(new UnexpectedValueException('No content received'));

                return;
            }

            $deferred->resolve(new Query($body));
        });

        return $deferred->promise();
    }

    /** @return Promise<void> */
    public function updateQuery(
        EndPoint $endPoint,
        string $name,
        string $query,
        ?bool $emitEnabled = null,
        ?UserCredentials $userCredentials = null
    ): Promise {
        $url = '/projection/%s/query';

        if (null !== $emitEnabled) {
            $url .= '?emit=' . (int) $emitEnabled;
        }

        return $this->sendPut(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                $url,
                $name
            ),
            $query,
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /** @return Promise<void> */
    public function reset(
        EndPoint $endPoint,
        string $name,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendPost(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s/command/reset',
                $name
            ),
            '',
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /** @return Promise<void> */
    public function delete(
        EndPoint $endPoint,
        string $name,
        bool $deleteEmittedStreams,
        ?UserCredentials $userCredentials = null
    ): Promise {
        return $this->sendDelete(
            EndpointExtensions::formatStringToHttpUrl(
                $endPoint,
                $this->httpSchema,
                '/projection/%s?deleteEmittedStreams=%d',
                $name,
                (string) (int) $deleteEmittedStreams
            ),
            $userCredentials,
            HttpStatusCode::OK
        );
    }

    /** @return Promise<string> */
    private function sendGet(
        string $url,
        ?UserCredentials $userCredentials,
        int $expectedCode
    ): Promise {
        $deferred = new Deferred();

        $this->client->get(
            $url,
            $userCredentials,
            function (Response $response) use ($deferred, $expectedCode, $url): void {
                if ($response->getStatus() === $expectedCode) {
                    $deferred->resolve($response->getBody()->buffer());
                } else {
                    $deferred->fail(new ProjectionCommandFailed(
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
    private function sendDelete(
        string $url,
        ?UserCredentials $userCredentials,
        int $expectedCode
    ): Promise {
        $deferred = new Deferred();

        $this->client->delete(
            $url,
            $userCredentials,
            function (Response $response) use ($deferred, $expectedCode, $url): void {
                if ($response->getStatus() === $expectedCode) {
                    $deferred->resolve();
                } else {
                    $deferred->fail(new ProjectionCommandFailed(
                        $response->getStatus(),
                        \sprintf(
                            'Server returned %d (%s) for DELETE on %s',
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
    private function sendPut(
        string $url,
        string $content,
        ?UserCredentials $userCredentials,
        int $expectedCode
    ): Promise {
        $deferred = new Deferred();

        $this->client->put(
            $url,
            $content,
            'application/json',
            $userCredentials,
            function (Response $response) use ($deferred, $expectedCode, $url): void {
                if ($response->getStatus() === $expectedCode) {
                    $deferred->resolve();
                } else {
                    $deferred->fail(new ProjectionCommandFailed(
                        $response->getStatus(),
                        \sprintf(
                            'Server returned %d (%s) for PUT on %s',
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
                    $deferred->resolve();
                } elseif ($response->getStatus() === HttpStatusCode::CONFLICT) {
                    $deferred->fail(new ProjectionCommandConflict($response->getStatus(), $response->getReason()));
                } else {
                    $deferred->fail(new ProjectionCommandFailed(
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

    private function buildProjectionDetails(array $entry): ProjectionDetails
    {
        return new ProjectionDetails(
            $entry['coreProcessingTime'],
            $entry['version'],
            $entry['epoch'],
            $entry['effectiveName'],
            $entry['writesInProgress'],
            $entry['readsInProgress'],
            $entry['partitionsCached'],
            $entry['status'],
            $entry['stateReason'] ?? null,
            $entry['name'],
            $entry['mode'],
            $entry['position'],
            $entry['progress'],
            $entry['lastCheckpoint'] ?? null,
            $entry['eventsProcessedAfterRestart'],
            $entry['statusUrl'],
            $entry['stateUrl'],
            $entry['resultUrl'],
            $entry['queryUrl'],
            $entry['enableCommandUrl'],
            $entry['disableCommandUrl'],
            $entry['checkpointStatus'] ?? null,
            $entry['bufferedEvents'],
            $entry['writePendingEventsBeforeCheckpoint'],
            $entry['writePendingEventsAfterCheckpoint']
        );
    }

    private function buildProjectionStatistics(array $entry): ProjectionStatistics
    {
        $projections = \array_reduce($entry['projections'], function (array $carrier, array $entry) {
            $carrier[] = $this->buildProjectionDetails($entry);

            return $carrier;
        }, []);

        return new ProjectionStatistics($projections);
    }
}
