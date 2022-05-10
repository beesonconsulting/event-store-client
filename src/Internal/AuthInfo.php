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

namespace Prooph\EventStoreClient\Internal;

/**
 * @internal
 *
 * @psalm-immutable
 */
class AuthInfo
{
    private string $correlationId;
    private int $timestamp;

    public function __construct(string $correlationId, int $timestamp)
    {
        $this->correlationId = $correlationId;
        $this->timestamp = $timestamp;
    }

    /** @psalm-pure */
    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /** @psalm-pure */
    public function timestamp(): int
    {
        return $this->timestamp;
    }
}
