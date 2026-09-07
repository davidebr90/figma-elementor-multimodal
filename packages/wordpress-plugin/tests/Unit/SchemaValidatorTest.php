<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Infrastructure\SchemaValidator;
use FEM\Infrastructure\DocumentIntegrity;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    public function testUnknownWidgetIsRejectedAtTheImportBoundary(): void
    {
        $document = $this->document();
        $document['nodes']['root']['widget'] = 'arbitrary-script';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('widget');
        (new SchemaValidator())->assertValid($document);
    }

    public function testDocumentIntegrityDetectsContentTampering(): void
    {
        $document = $this->document();
        $document['nodes']['root']['widget'] = 'heading';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('integrity');
        (new SchemaValidator())->assertValid($document);
    }

    public function testEditableUsesTheSamePropertyPathAsItsCapability(): void
    {
        $document = $this->document();
        $document['capabilities'] = [['nodeId' => 'root', 'propertyPath' => 'text.characters']];
        $document['editables'] = [['nodeId' => 'root', 'propertyPath' => 'text.characters']];
        $document['integrity']['contentHash'] = DocumentIntegrity::contentHash($document);

        (new SchemaValidator())->assertValid($document);
        self::assertTrue(true);
    }

    /** @return array<string,mixed> */
    private function document(): array
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-minimal.json'), true, 512, JSON_THROW_ON_ERROR);
        return $document;
    }
}
