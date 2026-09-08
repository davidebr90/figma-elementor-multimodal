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

    public function testDocumentWithoutAFigmaRevisionIsRejectedBeforeStaging(): void
    {
        $document = $this->document();
        $document['revisions']['figmaRevision'] = '';
        $document['integrity']['contentHash'] = DocumentIntegrity::contentHash($document);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('revision');
        (new SchemaValidator())->assertValid($document);
    }

    public function testCurrentV1ResponsiveFixturePreservesDesktopAndMobileIntent(): void
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-responsive.json'), true, 512, JSON_THROW_ON_ERROR);

        (new SchemaValidator())->assertValid($document);

        self::assertSame(48, $document['nodes']['root']['layout']['padding']['top']);
        self::assertSame(32, $document['nodes']['root']['responsive']['tablet']['layout']['padding']['top']);
        self::assertSame(20, $document['nodes']['root']['responsive']['mobile']['layout']['padding']['top']);
        self::assertFalse($document['nodes']['heading']['responsive']['mobile']['visible']);
    }

    public function testV11AcceptsOnlyAdditiveStructuredMetadata(): void
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-v11.json'), true, 512, JSON_THROW_ON_ERROR);

        (new SchemaValidator())->assertValid($document);
        self::assertSame('1.1.0', $document['schemaVersion']);
        self::assertSame('node', $document['bindings'][0]['propertyPath']);
    }

    public function testV11RejectsDuplicateBindingPropertyMappings(): void
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-v11.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['bindings'][] = $document['bindings'][0];
        $document['integrity']['contentHash'] = DocumentIntegrity::contentHash($document);

        $this->expectExceptionMessage('duplicate property mapping');
        (new SchemaValidator())->assertValid($document);
    }

    /** @return array<string,mixed> */
    private function document(): array
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-minimal.json'), true, 512, JSON_THROW_ON_ERROR);
        return $document;
    }
}
