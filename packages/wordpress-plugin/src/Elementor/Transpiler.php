<?php

declare(strict_types=1);

namespace FEM\Elementor;

use FEM\Rendering\ClassMap;

/**
 * Converts a FEM document into native Elementor elements.
 *
 * Every node carries a widget choice decided in Figma (explicit override, naming
 * convention, or heuristic). This class only maps that choice onto Elementor's
 * data shape: it never guesses a second time. Values that exist as Elementor
 * globals are bound through __globals__ rather than inlined, so editing a global
 * colour or font in Site Settings still propagates to imported sections.
 */
final class Transpiler
{
    /** Figma alignment names to their Elementor flex equivalents. */
    private const ALIGN = ['MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'SPACE_BETWEEN' => 'space-between', 'BASELINE' => 'baseline'];

    /** @var array<string,string> */
    private array $colors;

    /** @var array<string,array{id:string,title:string}> */
    private array $typography;

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string,mixed> */
    private array $document = [];

    /** @var array<string,bool> */
    private array $elementIds = [];

    public function __construct(GlobalStyles $globals = new GlobalStyles(), private readonly MediaLibrary $media = new MediaLibrary(), private readonly ClassMap $classes = new ClassMap())
    {
        $this->colors = $globals->colorIndex();
        $this->typography = $globals->typographyIndex();
    }

    /**
     * @param array<string,mixed> $document
     * @return array{elements:list<array<string,mixed>>,notes:list<string>}
     */
    public function transpile(array $document): array
    {
        $this->document = $document;
        $this->notes = [];
        $this->elementIds = [];
        $nodes = is_array($document['nodes'] ?? null) ? $document['nodes'] : [];
        $elements = [];
        foreach ((array) ($document['roots'] ?? []) as $rootId) {
            $element = $this->element((string) $rootId, $nodes, true);
            if ($element !== null) {
                $elements[] = $element;
            }
        }
        return ['elements' => $elements, 'notes' => array_values(array_unique($this->notes))];
    }

    /**
     * @param array<string,mixed> $nodes
     * @return array<string,mixed>|null
     */
    private function element(string $id, array $nodes, bool $isRoot = false): ?array
    {
        $node = $nodes[$id] ?? null;
        if (!is_array($node)) {
            return null;
        }
        $widget = (string) ($node['widget'] ?? 'container');

        $element = match ($widget) {
            'heading' => $this->heading($node),
            'text-editor' => $this->textEditor($node),
            'button' => $this->button($node),
            'image' => $this->image($node),
            'image-gallery', 'image-carousel' => $this->gallery($node, $nodes, $widget),
            'reviews' => $this->reviews($node, $nodes),
            'divider' => $this->divider($node),
            'spacer' => $this->spacer($node),
            'nested-accordion' => $this->accordion($node, $nodes),
            default => $this->container($node, $nodes, $isRoot),
        };
        return $this->withMapping($element, $node, (string) ($element['widgetType'] ?? $element['elType'] ?? $widget));
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $nodes
     * @return array<string,mixed>
     */
    private function container(array $node, array $nodes, bool $isRoot): array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $settings = ['_title' => (string) ($node['content']['name'] ?? 'FEM')];
        $globals = [];

        if (($layout['mode'] ?? '') === 'grid') {
            $settings['container_type'] = 'grid';
            $settings['grid_columns_grid'] = ['unit' => 'fr', 'size' => (int) ($layout['columns'] ?? 2), 'sizes' => []];
            if (!empty($layout['rows'])) {
                $settings['grid_rows_grid'] = ['unit' => 'fr', 'size' => (int) $layout['rows'], 'sizes' => []];
            }
            $settings['grid_gaps'] = $this->gaps($layout + ['direction' => 'row']);
        } else {
            $settings['container_type'] = 'flex';
            $settings['flex_direction'] = ($layout['direction'] ?? 'column') === 'row' ? 'row' : 'column';
            $settings['flex_gap'] = $this->gaps($layout);
            $settings['flex_justify_content'] = self::ALIGN[(string) ($layout['justify'] ?? 'MIN')] ?? 'flex-start';
            $settings['flex_align_items'] = self::ALIGN[(string) ($layout['align'] ?? 'MIN')] ?? 'flex-start';
            if (!empty($layout['wrap'])) {
                $settings['flex_wrap'] = 'wrap';
            }
        }

        if (isset($layout['padding']) && is_array($layout['padding'])) {
            $settings['padding'] = $this->box($layout['padding']);
            $settings['padding_tablet'] = $this->box($this->scaleBox($layout['padding'], 0.7, 40));
            $settings['padding_mobile'] = $this->box($this->scaleBox($layout['padding'], 0.45, 24));
        }
        $this->addResponsiveFlex($settings, $layout, count((array) ($node['children'] ?? [])));
        // Imported sections are laid out by their own padding, so the container must
        // not also apply Elementor's boxed max-width.
        $settings['content_width'] = $isRoot ? 'boxed' : 'full';
        if ($isRoot && !empty($layout['width'])) {
            // Keep the artboard width instead of Elementor's default boxed max-width.
            $settings['width'] = ['unit' => 'px', 'size' => (int) $layout['width'], 'sizes' => []];
        }
        if (!$isRoot && ($layout['sizingH'] ?? '') === 'FIXED' && !empty($layout['width'])) {
            $settings['width'] = ['unit' => 'px', 'size' => (int) $layout['width'], 'sizes' => []];
            // A width copied from a 1280px artboard would overflow a phone.
            $settings['width_mobile'] = ['unit' => '%', 'size' => 100, 'sizes' => []];
        }

        if (isset($style['background'])) {
            $settings['background_background'] = 'classic';
            $this->colorSetting($settings, $globals, 'background_color', (string) $style['background']);
        }
        if (isset($style['backgroundImage'])) {
            $attachment = $this->media->attachmentFor((string) $style['backgroundImage']);
            if ($attachment !== null) {
                $settings['background_background'] = 'classic';
                $settings['background_image'] = ['id' => $attachment['id'], 'url' => $attachment['url']];
                $settings['background_size'] = 'cover';
                $settings['background_position'] = 'center center';
            } else {
                $this->notes[] = 'Background image for "' . ($node['content']['name'] ?? '') . '" could not be attached.';
            }
        }
        if (!empty($style['radius'])) {
            $settings['border_radius'] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], (int) $style['radius']), true);
        }
        if (!empty($style['borderWidth'])) {
            $settings['border_border'] = 'solid';
            $settings['border_width'] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], (int) $style['borderWidth']), true);
            if (isset($style['borderColor'])) {
                $this->colorSetting($settings, $globals, 'border_color', (string) $style['borderColor']);
            }
        }

        // Explicit viewport values take precedence over every inferred default.
        $this->applyExplicitResponsive($settings, $node);
        $children = [];
        foreach ((array) ($node['children'] ?? []) as $childId) {
            $child = $this->element((string) $childId, $nodes);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return $this->wrap('container', null, $settings, $globals, $children);
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function heading(array $node): array
    {
        $text = is_array($node['text'] ?? null) ? $node['text'] : [];
        $settings = [
            '_title' => (string) ($node['content']['name'] ?? 'Heading'),
            'title' => (string) ($text['characters'] ?? ''),
            'header_size' => $this->headingLevel($node, $text),
            'align' => $this->align($text),
        ];
        $globals = [];
        if (isset($text['color'])) {
            $this->colorSetting($settings, $globals, 'title_color', (string) $text['color']);
        }
        $this->typographySetting($settings, $globals, 'typography', $text);
        $this->applyExplicitTypography($settings, $node, 'typography');
        return $this->wrap('widget', 'heading', $settings, $globals, []);
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function textEditor(array $node): array
    {
        $text = is_array($node['text'] ?? null) ? $node['text'] : [];
        $settings = [
            '_title' => (string) ($node['content']['name'] ?? 'Text'),
            'editor' => '<p>' . esc_html((string) ($text['characters'] ?? '')) . '</p>',
            'align' => $this->align($text),
        ];
        $globals = [];
        if (isset($text['color'])) {
            $this->colorSetting($settings, $globals, 'text_color', (string) $text['color']);
        }
        $this->typographySetting($settings, $globals, 'typography', $text);
        $this->applyExplicitTypography($settings, $node, 'typography');
        return $this->wrap('widget', 'text-editor', $settings, $globals, []);
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function button(array $node): array
    {
        // The label lives in the single text child that made this look like a button.
        $label = (string) ($node['content']['characters'] ?? $node['content']['name'] ?? 'Button');
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $text = is_array($node['text'] ?? null) ? $node['text'] : [];
        $settings = ['_title' => (string) ($node['content']['name'] ?? 'Button'), 'text' => $label, 'align' => $this->align($text)];
        $globals = [];
        if (isset($node['link'])) {
            $settings['link'] = ['url' => (string) $node['link'], 'is_external' => '', 'nofollow' => ''];
        }
        if (isset($style['background'])) {
            $this->colorSetting($settings, $globals, 'background_color', (string) $style['background']);
        } else {
            // An outline button has no fill in Figma; without this Elementor would
            // paint it with its own default accent colour.
            $this->colorSetting($settings, $globals, 'background_color', 'RGBA(0,0,0,0)');
        }
        if (!empty($style['borderWidth'])) {
            $settings['border_border'] = 'solid';
            $settings['border_width'] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], (int) $style['borderWidth']), true);
            if (isset($style['borderColor'])) {
                $this->colorSetting($settings, $globals, 'border_color', (string) $style['borderColor']);
            }
        }
        if (isset($text['color'])) {
            $this->colorSetting($settings, $globals, 'button_text_color', (string) $text['color']);
        }
        if ($text !== []) {
            $this->typographySetting($settings, $globals, 'typography', $text);
        }
        $this->applyExplicitResponsive($settings, $node);
        $this->applyExplicitTypography($settings, $node, 'typography');
        if (!empty($style['radius'])) {
            $settings['border_radius'] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], (int) $style['radius']), true);
        }
        return $this->wrap('widget', 'button', $settings, $globals, []);
    }

    /**
     * Each child frame becomes one accordion item: its layer name is the title and
     * its contents become the panel. Figma has no accordion, so this only runs when
     * the layer was named or mapped as one.
     * @param array<string,mixed> $node
     * @param array<string,mixed> $nodes
     * @return array<string,mixed>
     */
    private function accordion(array $node, array $nodes): array
    {
        $items = [];
        $panels = [];
        foreach ((array) ($node['children'] ?? []) as $childId) {
            $child = $nodes[(string) $childId] ?? null;
            if (!is_array($child)) {
                continue;
            }
            $itemId = substr(bin2hex(random_bytes(4)), 0, 7);
            $items[] = ['item_title' => (string) ($child['content']['name'] ?? 'Item'), '_id' => $itemId];
            $panel = $this->withMapping($this->container($child, $nodes, false), $child, 'container');
            $panel['isInner'] = true;
            $panels[] = $panel;
        }
        if ($items === []) {
            $this->notes[] = 'Accordion "' . ($node['content']['name'] ?? '') . '" had no child layers; imported as a container.';
            return $this->container($node, $nodes, false);
        }
        $settings = ['_title' => (string) ($node['content']['name'] ?? 'Accordion'), 'items' => $items];
        return $this->wrap('widget', 'nested-accordion', $settings, [], $panels);
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function divider(array $node): array
    {
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        $settings = ['_title' => (string) ($node['content']['name'] ?? 'Divider'), 'style' => 'solid'];
        $globals = [];
        $weight = (int) ($layout['height'] ?? 1);
        $settings['weight'] = ['unit' => 'px', 'size' => max(1, min($weight, 10)), 'sizes' => []];
        $color = $style['background'] ?? $style['borderColor'] ?? null;
        if ($color !== null) {
            $this->colorSetting($settings, $globals, 'color', (string) $color);
        }
        return $this->wrap('widget', 'divider', $settings, $globals, []);
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function spacer(array $node): array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        $settings = [
            '_title' => (string) ($node['content']['name'] ?? 'Spacer'),
            'space' => ['unit' => 'px', 'size' => max(1, (int) ($layout['height'] ?? 20)), 'sizes' => []],
        ];
        return $this->wrap('widget', 'spacer', $settings, [], []);
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function image(array $node): array
    {
        $sha = (string) ($node['image']['sha256'] ?? '');
        $attachment = $sha !== '' ? $this->media->attachmentFor($sha) : null;
        $settings = ['_title' => (string) ($node['content']['name'] ?? 'Image'), 'image_size' => 'full'];
        if ($attachment === null) {
            $this->notes[] = 'Image "' . ($node['content']['name'] ?? $sha) . '" had no uploaded asset; the widget is empty.';
            $settings['image'] = ['url' => '', 'id' => ''];
        } else {
            $settings['image'] = ['id' => $attachment['id'], 'url' => $attachment['url']];
        }
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        if (!empty($layout['width'])) {
            $settings['width'] = ['unit' => 'px', 'size' => (int) $layout['width'], 'sizes' => []];
        }
        $globals = [];
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        if (!empty($style['radius'])) {
            $settings['image_border_radius'] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], (int) $style['radius']), true);
        }
        $responsive = is_array($node['responsive'] ?? null) ? $node['responsive'] : [];
        foreach (['tablet' => '_tablet', 'mobile' => '_mobile'] as $viewport => $suffix) {
            $values = is_array($responsive[$viewport] ?? null) ? $responsive[$viewport] : [];
            if (isset($values['width']) && is_numeric($values['width'])) {
                $settings['width' . $suffix] = ['unit' => 'px', 'size' => max(0, (int) $values['width']), 'sizes' => []];
            }
            if (isset($values['radius']) && is_numeric($values['radius'])) {
                $settings['image_border_radius' . $suffix] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], max(0, min(999, (int) $values['radius']))), true);
            }
        }
        return $this->wrap('widget', 'image', $settings, $globals, []);
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $nodes
     * @return array<string,mixed>
     */
    private function gallery(array $node, array $nodes, string $widget): array
    {
        $images = [];
        foreach ((array) ($node['children'] ?? []) as $childId) {
            $child = $nodes[(string) $childId] ?? null;
            $sha = is_array($child) ? (string) ($child['image']['sha256'] ?? '') : '';
            $attachment = $sha !== '' ? $this->media->attachmentFor($sha) : null;
            if ($attachment !== null) {
                $images[] = ['id' => $attachment['id'], 'url' => $attachment['url']];
            }
        }
        if ($images === []) {
            $this->notes[] = 'Gallery "' . ($node['content']['name'] ?? '') . '" had no usable images; imported as a container instead.';
            return $this->container($node, $nodes, false);
        }
        $type = $widget === 'image-carousel' ? 'image-carousel' : 'image-gallery';
        $settings = ['_title' => (string) ($node['content']['name'] ?? 'Gallery')];
        $settings[$type === 'image-carousel' ? 'carousel' : 'wp_gallery'] = $images;
        return $this->wrap('widget', $type, $settings, [], []);
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $nodes @return array<string,mixed> */
    private function reviews(array $node, array $nodes): array
    {
        $slides = [];
        foreach ((array) ($node['children'] ?? []) as $childId) {
            $child = $nodes[(string) $childId] ?? null;
            if (!is_array($child)) {
                continue;
            }
            $texts = $this->textsUnder($child, $nodes);
            if ($texts === []) {
                continue;
            }
            usort($texts, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
            $slides[] = [
                '_id' => substr(hash('sha256', (string) $childId), 0, 7),
                'content' => $texts[0],
                'name' => $texts[1] ?? '',
                'title' => $texts[2] ?? '',
                'rating' => '5',
            ];
        }
        if ($slides === []) {
            $this->notes[] = 'Reviews "' . ($node['content']['name'] ?? '') . '" had no readable cards; imported as a container.';
            return $this->container($node, $nodes, false);
        }
        return $this->wrap('widget', 'reviews', [
            '_title' => (string) ($node['content']['name'] ?? 'Reviews'),
            'slides' => $slides,
            'slides_per_view' => (string) min(3, count($slides)),
            'slides_to_scroll' => '1',
            'show_arrows' => count($slides) > 1 ? 'yes' : '',
            'pagination' => count($slides) > 1 ? 'bullets' : '',
        ], [], []);
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $nodes @return list<string> */
    private function textsUnder(array $node, array $nodes, int $depth = 0): array
    {
        $texts = [];
        $own = trim((string) ($node['text']['characters'] ?? ''));
        if ($own !== '') {
            $texts[] = $own;
        }
        if ($depth >= 6) {
            return $texts;
        }
        foreach ((array) ($node['children'] ?? []) as $childId) {
            $child = $nodes[(string) $childId] ?? null;
            if (is_array($child)) {
                $texts = array_merge($texts, $this->textsUnder($child, $nodes, $depth + 1));
            }
        }
        return $texts;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,string> $globals
     */
    private function colorSetting(array &$settings, array &$globals, string $control, string $value): void
    {
        $globalId = $this->colors[GlobalStyles::colorKey($value)] ?? null;
        if ($globalId !== null) {
            $globals[$control] = 'globals/colors?id=' . $globalId;
            return;
        }
        $settings[$control] = $value;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,string> $globals
     * @param array<string,mixed> $text
     */
    private function typographySetting(array &$settings, array &$globals, string $control, array $text): void
    {
        $family = (string) ($text['fontFamily'] ?? '');
        $size = $text['fontSize'] ?? null;
        $weight = (string) ($text['fontWeight'] ?? '');
        if ($family !== '' && $size !== null && $weight !== '') {
            $key = GlobalStyles::typographyKey($family, (float) $size, $weight);
            if (isset($this->typography[$key])) {
                // Elementor keys a group control's global by the group's own switcher,
                // so it is "typography_typography" and not just "typography".
                $globals[$control . '_typography'] = 'globals/typography?id=' . $this->typography[$key]['id'];
                return;
            }
        }
        // No matching global: keep the literal values so the section still looks right.
        $settings[$control . '_typography'] = 'custom';
        if ($family !== '') {
            $settings[$control . '_font_family'] = $family;
        }
        if ($size !== null) {
            $settings[$control . '_font_size'] = ['unit' => 'px', 'size' => (float) $size, 'sizes' => []];
            // Only literal sizes need scaling; a bound global carries its own.
            if ((float) $size > 24) {
                $settings[$control . '_font_size_tablet'] = ['unit' => 'px', 'size' => round((float) $size * 0.8), 'sizes' => []];
                $settings[$control . '_font_size_mobile'] = ['unit' => 'px', 'size' => max(20.0, round((float) $size * 0.6)), 'sizes' => []];
            }
        }
        if ($weight !== '') {
            $settings[$control . '_font_weight'] = $weight;
        }
        if (isset($text['lineHeight'])) {
            $settings[$control . '_line_height'] = ['unit' => 'px', 'size' => (float) $text['lineHeight'], 'sizes' => []];
        }
        if (isset($text['letterSpacing'])) {
            $settings[$control . '_letter_spacing'] = ['unit' => 'px', 'size' => (float) $text['letterSpacing'], 'sizes' => []];
        }
        if (isset($text['transform'])) {
            $settings[$control . '_text_transform'] = (string) $text['transform'];
        }
        if ($family !== '') {
            $this->notes[] = 'No Elementor global matches ' . $family . ' ' . (string) $size . '/' . $weight . '; literal values were used.';
        }
    }

    /** Figma reports JUSTIFIED; Elementor's control expects "justify". @param array<string,mixed> $text */
    private function align(array $text): string
    {
        $align = strtolower((string) ($text['align'] ?? 'left'));
        return $align === 'justified' ? 'justify' : $align;
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $text */
    private function headingLevel(array $node, array $text): string
    {
        $name = strtolower((string) ($node['content']['name'] ?? ''));
        if (preg_match('/\bh([1-6])\b/', $name, $matches) === 1) {
            return 'h' . $matches[1];
        }
        $size = (float) ($text['fontSize'] ?? 16);
        return match (true) {
            $size >= 48 => 'h1',
            $size >= 32 => 'h2',
            $size >= 24 => 'h3',
            $size >= 20 => 'h4',
            default => 'h5',
        };
    }

    /**
     * Figma frames are drawn at one fixed width, so nothing in the file describes
     * smaller screens. These are deliberate defaults, not extracted values: rows
     * stack on phones and spacing shrinks, which is what the design would do.
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $layout
     */
    private function addResponsiveFlex(array &$settings, array $layout, int $childCount): void
    {
        if (($layout['mode'] ?? '') === 'grid') {
            $settings['grid_columns_grid_mobile'] = ['unit' => 'fr', 'size' => 1, 'sizes' => []];
            $settings['grid_columns_grid_tablet'] = ['unit' => 'fr', 'size' => max(1, (int) ceil(((int) ($layout['columns'] ?? 2)) / 2)), 'sizes' => []];
            return;
        }
        if (($layout['direction'] ?? 'column') === 'row' && $childCount >= 2) {
            $settings['flex_direction_mobile'] = 'column';
        }
        $gap = (int) ($layout['gap'] ?? 0);
        if ($gap > 0) {
            $direction = (string) ($layout['direction'] ?? 'column');
            $settings['flex_gap_tablet'] = $this->gaps(['gap' => (int) round($gap * 0.7), 'direction' => $direction]);
            // Stacked rows space vertically on phones, so the gap follows the new axis.
            $settings['flex_gap_mobile'] = $this->gaps(['gap' => (int) round($gap * 0.5), 'direction' => isset($settings['flex_direction_mobile']) ? 'column' : $direction]);
        }
    }

    /** Applies real Figma viewport overrides; heuristics remain the fallback. @param array<string,mixed> $settings @param array<string,mixed> $node */
    private function applyExplicitResponsive(array &$settings, array $node): void
    {
        $responsive = is_array($node['responsive'] ?? null) ? $node['responsive'] : [];
        foreach (['tablet' => '_tablet', 'mobile' => '_mobile'] as $viewport => $suffix) {
            $values = is_array($responsive[$viewport] ?? null) ? $responsive[$viewport] : [];
            if (is_array($values['padding'] ?? null)) {
                $settings['padding' . $suffix] = $this->box($values['padding']);
            }
            if (isset($values['width']) && is_numeric($values['width'])) {
                $settings['width' . $suffix] = ['unit' => 'px', 'size' => max(0, (int) $values['width']), 'sizes' => []];
            }
            if (isset($values['direction']) && in_array($values['direction'], ['row', 'column'], true)) {
                $settings['flex_direction' . $suffix] = $values['direction'];
            }
            if (isset($values['gap']) && is_numeric($values['gap'])) {
                $settings['flex_gap' . $suffix] = $this->gaps(['gap' => max(0, (int) $values['gap']), 'direction' => (string) ($values['direction'] ?? 'column')]);
            }
            if (isset($values['background']) && is_string($values['background']) && $this->isSafeColor($values['background'])) {
                $settings['background_color' . $suffix] = $values['background'];
            }
            if (isset($values['radius']) && is_numeric($values['radius'])) {
                $settings['border_radius' . $suffix] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], max(0, min(999, (int) $values['radius']))), true);
            }
            if (isset($values['borderWidth']) && is_numeric($values['borderWidth'])) {
                $settings['border_border'] = 'solid';
                $settings['border_width' . $suffix] = $this->box(array_fill_keys(['top', 'right', 'bottom', 'left'], max(0, min(100, (int) $values['borderWidth']))), true);
            }
            if (isset($values['borderColor']) && is_string($values['borderColor']) && $this->isSafeColor($values['borderColor'])) {
                $settings['border_color' . $suffix] = $values['borderColor'];
            }
        }
    }

    /** Applies an explicitly captured Figma text size after the base fallback. @param array<string,mixed> $settings @param array<string,mixed> $node */
    private function applyExplicitTypography(array &$settings, array $node, string $control): void
    {
        $responsive = is_array($node['responsive'] ?? null) ? $node['responsive'] : [];
        foreach (['tablet' => '_tablet', 'mobile' => '_mobile'] as $viewport => $suffix) {
            $values = is_array($responsive[$viewport] ?? null) ? $responsive[$viewport] : [];
            if (isset($values['fontSize']) && is_numeric($values['fontSize'])) {
                $settings[$control . '_font_size' . $suffix] = ['unit' => 'px', 'size' => max(1, min(400, (float) $values['fontSize'])), 'sizes' => []];
            }
        }
    }

    private function isSafeColor(string $value): bool
    {
        return preg_match('/^(#[a-f0-9]{6}|rgba?\([0-9 .,]+\))$/i', trim($value)) === 1;
    }

    /**
     * @param array<string,mixed> $box
     * @return array<string,int>
     */
    private function scaleBox(array $box, float $factor, int $cap): array
    {
        $scaled = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = (int) round(((int) ($box[$side] ?? 0)) * $factor);
            $scaled[$side] = min($value, $cap);
        }
        return $scaled;
    }

    /**
     * Figma's itemSpacing runs along the layout's own axis, so a vertical stack
     * spaces its children with row-gap. Writing it into column-gap, as a single
     * "gap" value would, leaves a column layout with no visible spacing at all.
     * @param array<string,mixed> $layout
     * @return array<string,mixed>
     */
    private function gaps(array $layout): array
    {
        $main = (int) ($layout['gap'] ?? 0);
        // counterAxisSpacing only means anything once the layout wraps.
        $cross = !empty($layout['wrap']) && !empty($layout['rowGap']) ? (int) $layout['rowGap'] : $main;
        $isRow = ($layout['direction'] ?? 'column') === 'row';
        $column = $isRow ? $main : $cross;
        $row = $isRow ? $cross : $main;
        return ['unit' => 'px', 'size' => $row, 'column' => (string) $column, 'row' => (string) $row, 'isLinked' => $column === $row];
    }

    /** @param array<string,mixed> $box @return array<string,mixed> */
    private function box(array $box, bool $linked = false): array
    {
        return [
            'unit' => 'px',
            'top' => (string) (int) ($box['top'] ?? 0),
            'right' => (string) (int) ($box['right'] ?? 0),
            'bottom' => (string) (int) ($box['bottom'] ?? 0),
            'left' => (string) (int) ($box['left'] ?? 0),
            'isLinked' => $linked,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,string> $globals
     * @param list<array<string,mixed>> $children
     * @return array<string,mixed>
     */
    private function wrap(string $elType, ?string $widgetType, array $settings, array $globals, array $children): array
    {
        if ($globals !== []) {
            $settings['__globals__'] = $globals;
        }
        $element = ['id' => $this->nextElementId(), 'elType' => $elType, 'settings' => $settings, 'elements' => $children];
        if ($widgetType !== null) {
            $element['widgetType'] = $widgetType;
        }
        if ($elType === 'container') {
            $element['isInner'] = false;
        }
        return $element;
    }

    /** @param array<string,mixed> $element @param array<string,mixed> $node @return array<string,mixed> */
    private function withMapping(array $element, array $node, string $role): array
    {
        $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
        $classes = $this->classes->classes($this->document, $node, $role);
        $settings['css_classes'] = trim((string) ($settings['css_classes'] ?? '') . ' ' . $classes);
        // This opaque metadata is ignored by Elementor controls but retained in
        // document JSON. It is the authoritative site -> Figma lookup anchor.
        $settings['_fem'] = [
            'schemaVersion' => 1,
            'nodeId' => (string) ($node['id'] ?? ''),
            'sourceNodeId' => (string) ($node['sourceNodeId'] ?? ''),
            'sourceIdentity' => (string) ($this->document['source']['identity'] ?? ''),
        ];
        $element['settings'] = $settings;
        return $element;
    }

    private function nextElementId(): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $id = substr(bin2hex(random_bytes(4)), 0, 7);
            if (!isset($this->elementIds[$id])) {
                $this->elementIds[$id] = true;
                return $id;
            }
        }
        throw new \RuntimeException('Could not allocate a unique Elementor element ID.');
    }
}
