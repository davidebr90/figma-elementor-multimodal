<?php

declare(strict_types=1);

namespace FEM\Blocks;

use FEM\Rendering\ClassMap;

/** Converts the canonical FEM document to safe, native Gutenberg blocks. */
final class BlockRenderer
{
    /** @var array<string,bool> */
    private array $visiting = [];
    private int $renderedCount = 0;
    public function __construct(private readonly ClassMap $classes = new ClassMap(), private readonly BlockMediaResolver $media = new WpBlockMediaResolver())
    {
    }

    /** @param array<string,mixed> $document @return array{content:string,notes:list<string>} */
    public function render(array $document): array
    {
        $this->visiting = [];
        $this->renderedCount = 0;
        $nodes = is_array($document['nodes'] ?? null) ? $document['nodes'] : [];
        $notes = [];
        $blocks = [];
        foreach ((array) ($document['roots'] ?? []) as $root) {
            $block = $this->node((string) $root, $nodes, $document, $notes);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }
        return ['content' => implode("\n", $blocks), 'notes' => array_values(array_unique($notes))];
    }

    /** @param array<string,mixed> $nodes @param array<string,mixed> $document @param list<string> $notes */
    private function node(string $id, array $nodes, array $document, array &$notes): string
    {
        if (isset($this->visiting[$id]) || count($this->visiting) >= 256 || ++$this->renderedCount > 10000) {
            throw new \InvalidArgumentException('FEM graph is cyclic or exceeds rendering limits.');
        }
        $node = $nodes[$id] ?? null;
        if (!is_array($node)) {
            $notes[] = 'Skipped missing FEM node ' . $id . '.';
            return '';
        }
        $this->visiting[$id] = true;
        $role = (string) ($node['widget'] ?? 'container');
        $attrs = ['className' => $this->classes->classes($document, $node, $role), 'metadata' => ['fem' => ['nodeId' => (string) ($node['id'] ?? $id), 'version' => 1]]];
        $style = $this->style($node);
        if ($style !== []) {
            $attrs['style'] = $style;
        }
        if ($role === 'spacer') {
            $attrs['style']['height'] = $this->spacerHeight($node);
        }
        $children = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            $rendered = $this->node((string) $child, $nodes, $document, $notes);
            if ($rendered !== '') {
                $children[] = $rendered;
            }
        }
        $text = is_array($node['text'] ?? null) ? (string) ($node['text']['characters'] ?? '') : '';
        $content = str_replace("\n", '<br>', $this->escapeText($text));
        $level = $this->headingLevel($node);
        $wrapper = $this->wrapperAttributes($attrs, $role);
        $link = (string) ($node['link'] ?? '');
        $href = preg_match('~^(https?://|mailto:|tel:|/|#)~i', $link) === 1 && !str_starts_with($link, '//') ? ' href="' . $this->escapeText($link) . '"' : '';
        $block = match ($role) {
            'heading' => $this->serialize('core/heading', $attrs + ['level' => $level], '<h' . $level . $wrapper . '>' . $content . '</h' . $level . '>'),
            'text-editor' => $this->serialize('core/paragraph', $attrs, '<p' . $wrapper . '>' . $content . '</p>'),
            'button' => $this->serialize('core/buttons', [], '<div class="wp-block-buttons">' . $this->serialize('core/button', $attrs, '<div class="wp-block-button ' . $this->escapeText($attrs['className']) . '"><a' . $this->wrapperAttributes($attrs, 'button-link') . $href . '>' . $content . '</a></div>') . '</div>'),
            'image' => $this->image($id, $node, $attrs, $notes),
            'image-gallery' => $this->gallery($id, $attrs, $children, $notes, false),
            'image-carousel' => $this->gallery($id, $attrs, $children, $notes, true),
            'divider' => $this->serialize('core/separator', $attrs, '<hr' . $wrapper . '/>'),
            'spacer' => $this->serialize('core/spacer', $attrs + ['height' => $this->spacerHeight($node)], '<div' . $wrapper . ' aria-hidden="true"></div>'),
            default => $this->serialize('core/group', $attrs, '<div' . $wrapper . '>' . implode("\n", $children) . '</div>'),
        };
        unset($this->visiting[$id]);
        return $block;
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $attrs @param list<string> $notes */
    private function image(string $id, array $node, array $attrs, array &$notes): string
    {
        $sha256 = (string) ($node['image']['sha256'] ?? '');
        $asset = preg_match('/^[a-f0-9]{64}$/', $sha256) === 1 ? $this->media->resolve($sha256) : null;
        if ($asset === null) {
            $notes[] = 'Image media for node ' . $id . ' is unavailable; the node was retained as an empty group.';
            return $this->serialize('core/group', $attrs, '<div' . $this->wrapperAttributes($attrs, 'group') . '></div>');
        }
        $alt = (string) ($node['content']['name'] ?? '');
        $imageAttrs = $attrs + ['id' => $asset['id'], 'sizeSlug' => 'full', 'linkDestination' => 'none'];
        $classes = $this->wrapperAttributes($attrs, 'image');
        return $this->serialize('core/image', $imageAttrs, '<figure' . $classes . '><img src="' . $this->escapeText($asset['url']) . '" alt="' . $this->escapeText($alt) . '" class="wp-image-' . $asset['id'] . '"/></figure>');
    }

    /** @param array<string,mixed> $attrs @param list<string> $children @param list<string> $notes */
    private function gallery(string $id, array $attrs, array $children, array &$notes, bool $carousel): string
    {
        if ($children === []) {
            $notes[] = 'Gallery node ' . $id . ' contains no importable image children.';
        }
        if ($carousel) {
            $notes[] = 'Carousel node ' . $id . ' was emitted as an accessible native gallery group; configure animation in the editor if required.';
        }
        return $this->serialize('core/group', $attrs, '<div' . $this->wrapperAttributes($attrs, 'group') . '>' . implode("\n", $children) . '</div>');
    }

    /** @param array<string,mixed> $attrs */
    private function wrapperAttributes(array $attrs, string $role): string
    {
        $base = match ($role) {
            'heading' => 'wp-block-heading', 'text-editor' => '', 'image' => 'wp-block-image',
            'button-link' => 'wp-block-button__link', 'divider' => 'wp-block-separator',
            'spacer' => 'wp-block-spacer', default => 'wp-block-group',
        };
        $style = $attrs['style'] ?? [];
        $classes = array_filter([$base, $role === 'button-link' ? '' : $attrs['className']]);
        $css = [];
        if (isset($style['border']['color'])) {
            $classes[] = 'has-border-color';
            $css[] = 'border-color:' . $style['border']['color'];
        }
        foreach (['radius' => 'border-radius', 'style' => 'border-style', 'width' => 'border-width'] as $key => $property) {
            if (isset($style['border'][$key])) {
                $css[] = $property . ':' . $style['border'][$key];
            }
        }
        if (isset($style['color']['background'])) {
            $classes[] = 'has-background';
            $css[] = 'background-color:' . $style['color']['background'];
        }
        foreach (($style['spacing']['padding'] ?? []) as $side => $value) {
            $css[] = 'padding-' . $side . ':' . $value;
        }
        if (isset($style['height'])) {
            $css[] = 'height:' . $style['height'];
        }
        if ($role === 'divider') {
            $classes[] = 'has-alpha-channel-opacity';
            if (isset($style['color']['background'])) {
                $classes[] = 'has-text-color';
                $css[] = 'color:' . $style['color']['background'];
            }
        }
        if ($role === 'button-link') {
            $classes[] = 'wp-element-button';
        }
        return ($classes ? ' class="' . $this->escapeText(implode(' ', $classes)) . '"' : '') . ($css ? ' style="' . $this->escapeText(implode(';', $css)) . '"' : '');
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function style(array $node): array
    {
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        $output = [];
        if (isset($style['background']) && is_string($style['background']) && preg_match('/^#[a-f0-9]{6}$/i', $style['background'])) {
            $output['color']['background'] = strtolower($style['background']);
        }
        if (isset($style['radius']) && is_numeric($style['radius'])) {
            $output['border']['radius'] = max(0, min(999, (int) $style['radius'])) . 'px';
        }
        if (isset($style['borderWidth'], $style['borderColor']) && is_numeric($style['borderWidth']) && is_string($style['borderColor']) && preg_match('/^#[a-f0-9]{6}$/i', $style['borderColor'])) {
            $output['border']['width'] = max(0, min(100, (int) $style['borderWidth'])) . 'px';
            $output['border']['color'] = strtolower($style['borderColor']);
            $output['border']['style'] = 'solid';
        }
        if (is_array($layout['padding'] ?? null)) {
            $output['spacing']['padding'] = $this->box($layout['padding']);
        }
        $responsive = is_array($node['responsive'] ?? null) ? $node['responsive'] : [];
        foreach (['tablet' => '@tablet', 'mobile' => '@mobile'] as $source => $target) {
            if (is_array($responsive[$source] ?? null)) {
                $override = $this->styleOverride($responsive[$source]);
                if ($override !== []) {
                    $output[$target] = $override;
                }
            }
        }
        return $output;
    }

    /** @param array<string,mixed> $node */
    private function spacerHeight(array $node): string
    {
        $height = is_array($node['layout'] ?? null) ? ($node['layout']['height'] ?? 20) : 20;
        return max(1, min(2000, is_numeric($height) ? (int) $height : 20)) . 'px';
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function styleOverride(array $values): array
    {
        $result = [];
        if (isset($values['background']) && is_string($values['background']) && preg_match('/^#[a-f0-9]{6}$/i', $values['background'])) {
            $result['color']['background'] = strtolower($values['background']);
        }
        if (isset($values['fontSize']) && is_numeric($values['fontSize'])) {
            $result['typography']['fontSize'] = max(1, min(400, (int) $values['fontSize'])) . 'px';
        }
        if (is_array($values['padding'] ?? null)) {
            $result['spacing']['padding'] = $this->box($values['padding']);
        }
        if (isset($values['radius']) && is_numeric($values['radius'])) {
            $result['border']['radius'] = max(0, min(999, (int) $values['radius'])) . 'px';
        }
        return $result;
    }

    /** @param array<string,mixed> $box @return array<string,string> */
    private function box(array $box): array
    {
        $result = [];
        foreach (['top' => 'top', 'right' => 'right', 'bottom' => 'bottom', 'left' => 'left'] as $key => $side) {
            if (isset($box[$key]) && is_numeric($box[$key])) {
                $result[$side] = max(0, min(2000, (int) $box[$key])) . 'px';
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $attrs */
    private function serialize(string $name, array $attrs, string $inner): string
    {
        if ($attrs === []) {
            return '<!-- wp:' . $name . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
        }
        $json = function_exists('wp_json_encode')
            ? (string) wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Match WordPress comment-attribute escaping, including comment terminators.
        $json = str_replace(['--', '<', '>', '&', '\\"'], ['\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022'], $json);
        return $inner === '' ? '<!-- wp:' . $name . ' ' . $json . ' /-->' : '<!-- wp:' . $name . ' ' . $json . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
    }

    /** @param array<string,mixed> $node */
    private function headingLevel(array $node): int
    {
        $size = is_array($node['text'] ?? null) ? (float) ($node['text']['fontSize'] ?? 0) : 0;
        return $size >= 40 ? 1 : ($size >= 30 ? 2 : 3);
    }

    private function escapeText(string $value): string
    {
        return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
