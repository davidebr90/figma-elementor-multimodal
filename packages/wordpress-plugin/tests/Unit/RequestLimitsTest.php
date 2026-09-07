<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Api\RequestLimitException;
use FEM\Api\RequestLimits;
use PHPUnit\Framework\TestCase;

final class RequestLimitsTest extends TestCase
{
    public function testOversizedJsonBodyFailsBeforeDecode(): void
    {
        $this->expectException(RequestLimitException::class);
        $this->expectExceptionMessage('10 MiB');
        (new RequestLimits())->decodeJson(str_repeat('x', RequestLimits::JSON_BODY_BYTES + 1));
    }

    public function testNestedJsonBeyondTheApplicationLimitIsRejected(): void
    {
        $body = '{"document":' . str_repeat('[', RequestLimits::MAX_DEPTH + 1) . '0' . str_repeat(']', RequestLimits::MAX_DEPTH + 1) . '}';

        try {
            (new RequestLimits())->decodeJson($body);
            self::fail('Deep JSON must be rejected.');
        } catch (RequestLimitException $exception) {
            self::assertSame('max-depth', $exception->errorCode);
        }
    }

    public function testLongTextContentIsAcceptedWithinTheDocumentBudget(): void
    {
        $body = json_encode(['document' => ['nodes' => ['root' => ['text' => ['characters' => str_repeat('A', 1500)]]]]], JSON_THROW_ON_ERROR);

        $decoded = (new RequestLimits())->decodeJson($body);

        self::assertSame(1500, strlen($decoded['document']['nodes']['root']['text']['characters']));
    }

    public function testOversizedChildrenListIsRejected(): void
    {
        $body = json_encode(['document' => ['nodes' => ['root' => ['children' => array_fill(0, RequestLimits::MAX_CHILDREN + 1, 'node')]]]], JSON_THROW_ON_ERROR);

        try {
            (new RequestLimits())->decodeJson($body);
            self::fail('A node cannot have an unbounded number of children.');
        } catch (RequestLimitException $exception) {
            self::assertSame('max-array-length', $exception->errorCode);
        }
    }

    public function testRejectsAnImageWhosePixelCountWouldExhaustServerMemory(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 10000, 5000, 8, 6, 0, 0, 0) . "\x00\x00\x00\x00";

        $this->expectException(RequestLimitException::class);
        $this->expectExceptionMessage('pixel');
        (new RequestLimits())->assertAsset($png, hash('sha256', $png), 'image/png');
    }
}
