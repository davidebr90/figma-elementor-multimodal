<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Elementor\Transpiler;
use PHPUnit\Framework\TestCase;

/**
 * Covers the widget shapes that no Figma test frame exercised yet: grid
 * containers, galleries, carousels and accordions, plus responsive defaults.
 */
final class TranspilerTest extends TestCase
{
    public function testValidMotionIsRetainedOnlyInNamespacedFemMetadata(): void
    {
        $nodes = ['root' => self::node('root', 'container', ['motion' => ['preset' => 'fade-up', 'trigger' => 'viewport', 'durationMs' => 600, 'delayMs' => 0, 'easing' => 'power2.out', 'once' => true, 'staggerMs' => 0]])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'root'))['elements'][0]['settings'];

        self::assertSame('fade-up', $settings['_fem']['motion']['preset']);
        self::assertArrayNotHasKey('custom_css', $settings);
    }
    public function testExplicitMobileValuesOverrideInferredLayout(): void
    {
        $nodes = [
            'root' => self::node('root', 'container', [
                'children' => ['a', 'b'],
                'layout' => ['direction' => 'row', 'gap' => 40],
                'responsive' => ['mobile' => ['direction' => 'row', 'gap' => 7, 'width' => 320]],
            ]),
            'a' => self::node('a', 'container'),
            'b' => self::node('b', 'container'),
        ];
        $settings = (new Transpiler())->transpile(self::document($nodes, 'root'))['elements'][0]['settings'];
        self::assertSame('row', $settings['flex_direction_mobile']);
        self::assertSame('7', $settings['flex_gap_mobile']['column']);
        self::assertSame(320, $settings['width_mobile']['size']);
    }

    public function testExplicitMobileTypographyOverridesTheInferredHeadingSize(): void
    {
        $nodes = ['heading' => self::node('heading', 'heading', [
            'text' => ['characters' => 'Titolo', 'fontFamily' => 'Nonesuch', 'fontSize' => 72, 'fontWeight' => '800'],
            'responsive' => ['mobile' => ['fontSize' => 28]],
        ])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'heading'))['elements'][0]['settings'];

        self::assertSame(28.0, $settings['typography_font_size_mobile']['size']);
    }

    public function testExplicitMobilePaintAndBorderValuesAreProjectedToElementor(): void
    {
        $nodes = ['root' => self::node('root', 'container', [
            'responsive' => ['mobile' => [
                'background' => '#112233',
                'radius' => 18,
                'borderWidth' => 2,
                'borderColor' => '#445566',
            ]],
        ])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'root'))['elements'][0]['settings'];
        self::assertSame('#112233', $settings['background_color_mobile']);
        self::assertSame('18', $settings['border_radius_mobile']['top']);
        self::assertSame('2', $settings['border_width_mobile']['top']);
        self::assertSame('#445566', $settings['border_color_mobile']);
    }

    public function testExplicitMobileButtonPaintIsProjectedToElementor(): void
    {
        $nodes = ['button' => self::node('button', 'button', [
            'content' => ['name' => 'CTA', 'characters' => 'Apri'],
            'text' => ['characters' => 'Apri', 'fontSize' => 20],
            'responsive' => ['mobile' => [
                'background' => '#112233', 'radius' => 14,
                'borderWidth' => 2, 'borderColor' => '#445566',
            ]],
        ])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'button'))['elements'][0]['settings'];

        self::assertSame('#112233', $settings['background_color_mobile']);
        self::assertSame('14', $settings['border_radius_mobile']['top']);
        self::assertSame('2', $settings['border_width_mobile']['top']);
        self::assertSame('#445566', $settings['border_color_mobile']);
    }

    public function testExplicitMobileImageGeometryIsProjectedToElementor(): void
    {
        $nodes = ['image' => self::node('image', 'image', [
            'image' => ['sha256' => str_repeat('a', 64)],
            'layout' => ['width' => 640, 'height' => 400],
            'style' => ['radius' => 8],
            'responsive' => ['mobile' => ['width' => 280, 'radius' => 18]],
        ])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'image'))['elements'][0]['settings'];

        self::assertSame(280, $settings['width_mobile']['size']);
        self::assertSame('18', $settings['image_border_radius_mobile']['top']);
    }

    public function testEveryElementCarriesTheScopedClassMapAndSourceIdentity(): void
    {
        $document = self::document(['root' => self::node('root', 'container')], 'root');
        $document['source'] = ['identity' => 'figma:file:node'];

        $element = (new Transpiler())->transpile($document)['elements'][0];

        self::assertStringContainsString('fem-design-', $element['settings']['css_classes']);
        self::assertStringContainsString('fem-node-', $element['settings']['css_classes']);
        self::assertSame('root', $element['settings']['_fem']['nodeId']);
        self::assertSame('figma:file:node', $element['settings']['_fem']['sourceIdentity']);
    }

    public function testGeneratedElementIdsStayUniqueForADeepImport(): void
    {
        $nodes = ['root' => self::node('root', 'container', ['children' => []])];
        for ($index = 1; $index <= 1000; $index++) {
            $id = 'node-' . $index;
            $nodes['root']['children'][] = $id;
            $nodes[$id] = self::node($id, 'container');
        }

        $element = (new Transpiler())->transpile(self::document($nodes, 'root'))['elements'][0];
        $ids = array_map(static fn (array $child): string => $child['id'], $element['elements']);

        self::assertCount(1000, array_unique($ids));
    }

    /** @param array<string,mixed> $nodes @return array<string,mixed> */
    private static function document(array $nodes, string $root): array
    {
        return ['kind' => 'fem.document', 'schemaVersion' => '1.0.0', 'roots' => [$root], 'nodes' => $nodes];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function node(string $id, string $widget, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'widget' => $widget,
            'children' => [],
            'layout' => ['width' => 400, 'height' => 200],
            'style' => [],
            'content' => ['name' => $id],
        ], $overrides);
    }

    public function testGridContainerBecomesAGridWithResponsiveColumns(): void
    {
        $nodes = ['grid' => self::node('grid', 'container', [
            'layout' => ['mode' => 'grid', 'columns' => 4, 'rows' => 2, 'gap' => 24, 'padding' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40]],
        ])];

        $result = (new Transpiler())->transpile(self::document($nodes, 'grid'));
        $settings = $result['elements'][0]['settings'];

        self::assertSame('grid', $settings['container_type']);
        self::assertSame(4, $settings['grid_columns_grid']['size']);
        self::assertSame(1, $settings['grid_columns_grid_mobile']['size'], 'a grid must collapse to one column on phones');
        self::assertSame(2, $settings['grid_columns_grid_tablet']['size']);
        self::assertSame('18', $settings['padding_mobile']['top'], 'padding is scaled down, not copied');
    }

    public function testRowContainerStacksOnMobile(): void
    {
        $nodes = [
            'row' => self::node('row', 'container', ['children' => ['a', 'b'], 'layout' => ['mode' => 'flex', 'direction' => 'row', 'gap' => 40]]),
            'a' => self::node('a', 'container'),
            'b' => self::node('b', 'container'),
        ];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'row'))['elements'][0]['settings'];

        self::assertSame('row', $settings['flex_direction']);
        self::assertSame('column', $settings['flex_direction_mobile']);
        self::assertSame('20', $settings['flex_gap_mobile']['column'], 'the gap halves on phones');
    }

    public function testSingleChildRowKeepsItsDirection(): void
    {
        $nodes = [
            'row' => self::node('row', 'container', ['children' => ['a'], 'layout' => ['mode' => 'flex', 'direction' => 'row', 'gap' => 10]]),
            'a' => self::node('a', 'container'),
        ];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'row'))['elements'][0]['settings'];

        self::assertArrayNotHasKey('flex_direction_mobile', $settings, 'one child cannot stack, so nothing should change');
    }

    public function testGalleryWithoutUploadedImagesFallsBackToAContainer(): void
    {
        $nodes = [
            'gal' => self::node('gal', 'image-gallery', ['children' => ['i1', 'i2']]),
            'i1' => self::node('i1', 'image', ['image' => ['sha256' => str_repeat('a', 64)]]),
            'i2' => self::node('i2', 'image', ['image' => ['sha256' => str_repeat('b', 64)]]),
        ];

        $result = (new Transpiler())->transpile(self::document($nodes, 'gal'));

        self::assertSame('container', $result['elements'][0]['elType'], 'a gallery with no media must not produce an empty widget');
        self::assertNotEmpty($result['notes'], 'the fallback has to be reported');
    }

    public function testAccordionBuildsOneItemAndOnePanelPerChild(): void
    {
        $nodes = [
            'acc' => self::node('acc', 'nested-accordion', ['children' => ['q1', 'q2'], 'content' => ['name' => 'faq']]),
            'q1' => self::node('q1', 'container', ['content' => ['name' => 'Prima domanda']]),
            'q2' => self::node('q2', 'container', ['content' => ['name' => 'Seconda domanda']]),
        ];

        $element = (new Transpiler())->transpile(self::document($nodes, 'acc'))['elements'][0];

        self::assertSame('nested-accordion', $element['widgetType']);
        self::assertCount(2, $element['settings']['items']);
        self::assertSame('Prima domanda', $element['settings']['items'][0]['item_title']);
        self::assertCount(2, $element['elements'], 'each item needs its own panel container');
        self::assertTrue($element['elements'][0]['isInner']);
    }

    public function testAccordionWithoutChildrenDegradesToAContainer(): void
    {
        $nodes = ['acc' => self::node('acc', 'nested-accordion')];

        $result = (new Transpiler())->transpile(self::document($nodes, 'acc'));

        self::assertSame('container', $result['elements'][0]['elType']);
        self::assertNotEmpty($result['notes']);
    }

    public function testReviewsTurnReadableChildCardsIntoNativeReviewSlides(): void
    {
        $nodes = [
            'reviews' => self::node('reviews', 'reviews', ['children' => ['first', 'second'], 'content' => ['name' => 'Recensioni']]),
            'first' => self::node('first', 'container', ['children' => ['quote', 'name']]),
            'quote' => self::node('quote', 'text-editor', ['text' => ['characters' => 'Servizio eccellente e disponibilità immediata.']]),
            'name' => self::node('name', 'heading', ['text' => ['characters' => 'Anna']]),
            'second' => self::node('second', 'container', ['children' => ['quote2', 'name2']]),
            'quote2' => self::node('quote2', 'text-editor', ['text' => ['characters' => 'Professionali, veloci e molto precisi.']]),
            'name2' => self::node('name2', 'heading', ['text' => ['characters' => 'Luca']]),
        ];

        $element = (new Transpiler())->transpile(self::document($nodes, 'reviews'))['elements'][0];

        self::assertSame('reviews', $element['widgetType']);
        self::assertCount(2, $element['settings']['slides']);
        self::assertSame('Anna', $element['settings']['slides'][0]['name']);
    }

    public function testLiteralTypographyGetsResponsiveSizesButSmallTextDoesNot(): void
    {
        $nodes = [
            'big' => self::node('big', 'heading', ['text' => ['characters' => 'Titolo', 'fontFamily' => 'Nonesuch', 'fontSize' => 72, 'fontWeight' => '800']]),
        ];
        $settings = (new Transpiler())->transpile(self::document($nodes, 'big'))['elements'][0]['settings'];
        self::assertSame(43.0, $settings['typography_font_size_mobile']['size']);

        $small = ['s' => self::node('s', 'heading', ['text' => ['characters' => 'x', 'fontFamily' => 'Nonesuch', 'fontSize' => 20, 'fontWeight' => '400']])];
        $smallSettings = (new Transpiler())->transpile(self::document($small, 's'))['elements'][0]['settings'];
        self::assertArrayNotHasKey('typography_font_size_mobile', $smallSettings, 'small text is already readable on phones');
    }

    public function testVerticalStackSpacesOnTheRowAxis(): void
    {
        $nodes = [
            'col' => self::node('col', 'container', ['children' => ['a', 'b'], 'layout' => ['mode' => 'flex', 'direction' => 'column', 'gap' => 32]]),
            'a' => self::node('a', 'container'),
            'b' => self::node('b', 'container'),
        ];

        $gap = (new Transpiler())->transpile(self::document($nodes, 'col'))['elements'][0]['settings']['flex_gap'];

        self::assertSame('32', $gap['row'], 'a column stack spaces its children vertically');
        self::assertSame('32', $gap['column']);
    }

    public function testHorizontalRowSpacesOnTheColumnAxis(): void
    {
        $nodes = [
            'row' => self::node('row', 'container', ['children' => ['a', 'b'], 'layout' => ['mode' => 'flex', 'direction' => 'row', 'gap' => 24]]),
            'a' => self::node('a', 'container'),
            'b' => self::node('b', 'container'),
        ];

        $gap = (new Transpiler())->transpile(self::document($nodes, 'row'))['elements'][0]['settings']['flex_gap'];

        self::assertSame('24', $gap['column']);
        self::assertSame('24', $gap['row']);
    }

    public function testWrappingRowKeepsItsSeparateCrossAxisSpacing(): void
    {
        $nodes = [
            'row' => self::node('row', 'container', ['children' => ['a'], 'layout' => ['mode' => 'flex', 'direction' => 'row', 'gap' => 24, 'rowGap' => 8, 'wrap' => true]]),
            'a' => self::node('a', 'container'),
        ];

        $gap = (new Transpiler())->transpile(self::document($nodes, 'row'))['elements'][0]['settings']['flex_gap'];

        self::assertSame('24', $gap['column']);
        self::assertSame('8', $gap['row'], 'counterAxisSpacing only applies once the row wraps');
        self::assertFalse($gap['isLinked']);
    }

    public function testJustifiedTextBecomesElementorJustify(): void
    {
        $nodes = ['t' => self::node('t', 'text-editor', ['text' => ['characters' => 'Testo', 'align' => 'justified']])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 't'))['elements'][0]['settings'];

        self::assertSame('justify', $settings['align']);
    }

    public function testOutlineButtonKeepsItsBorderAndStaysTransparent(): void
    {
        $nodes = ['b' => self::node('b', 'button', [
            'content' => ['name' => 'cta', 'characters' => 'Prenota'],
            'style' => ['borderWidth' => 2, 'borderColor' => '#260007'],
            'text' => ['characters' => 'Prenota', 'color' => '#260007'],
        ])];

        $settings = (new Transpiler())->transpile(self::document($nodes, 'b'))['elements'][0]['settings'];

        self::assertSame('solid', $settings['border_border']);
        self::assertSame('2', $settings['border_width']['top']);
        $background = $settings['background_color'] ?? ($settings['__globals__']['background_color'] ?? null);
        self::assertNotEmpty($background, 'a fill-less button must be given a transparent background, not Elementor\'s default');
    }

    public function testHeadingLevelFollowsSize(): void
    {
        $cases = [72 => 'h1', 34 => 'h2', 24 => 'h3', 20 => 'h4', 14 => 'h5'];
        foreach ($cases as $size => $expected) {
            $nodes = ['h' => self::node('h', 'heading', ['text' => ['characters' => 'T', 'fontSize' => $size]])];
            $settings = (new Transpiler())->transpile(self::document($nodes, 'h'))['elements'][0]['settings'];
            self::assertSame($expected, $settings['header_size'], "size {$size} should map to {$expected}");
        }
    }
}
