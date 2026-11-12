<?php

declare(strict_types=1);

namespace Tests\Support;

trait PrivateAccess
{
    protected static function callPrivate(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    /** @return list<list<int>> the telegrams waiting to be sent */
    protected static function bufferOf(\Weishaupt $api): array
    {
        return (new \ReflectionProperty($api, 'telegramRequestBuffer'))->getValue($api);
    }
}
