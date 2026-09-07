<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Api\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class ResponseFactoryTest extends TestCase
{
    public function testSuccessResponseUsesTheVersionedEnvelope(): void
    {
        $response = (new ResponseFactory())->success(['importId' => 'abc'], 'request-1');

        self::assertSame(true, $response['success']);
        self::assertSame(['importId' => 'abc'], $response['data']);
        self::assertSame('request-1', $response['meta']['requestId']);
        self::assertSame([], $response['errors']);
    }

    public function testErrorResponseIncludesStableCodeAndHttpStatus(): void
    {
        $response = (new ResponseFactory())->error(413, 'max-bytes', 'Too large', 'request-2');

        self::assertSame(413, $response->get_status());
        self::assertSame('max-bytes', $response->get_data()['errors'][0]['code']);
    }
}
