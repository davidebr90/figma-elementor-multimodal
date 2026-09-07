<?php

declare(strict_types=1);

namespace FEM\Elementor;

/**
 * Syncs Figma variables and text styles into the Elementor global kit.
 *
 * Entries are matched by normalised title, so re-running updates the existing
 * global instead of creating a duplicate. Only the custom_* lists are managed:
 * system_colors and system_typography carry semantic roles (primary, accent, …)
 * that a designer assigns deliberately, so they are left alone.
 */
final class GlobalStyles
{
    public const SETTINGS_META = '_elementor_page_settings';

    /**
     * @param list<array<string,mixed>> $colors REST input validated per field below.
     * @param list<array<string,mixed>> $typography
     * @return array<string,mixed>
     */
    public function sync(array $colors, array $typography): array
    {
        $kitId = (int) get_option('elementor_active_kit');
        if ($kitId <= 0) {
            throw new \RuntimeException('No active Elementor kit was found.');
        }
        $settings = get_post_meta($kitId, self::SETTINGS_META, true);
        $settings = is_array($settings) ? $settings : [];

        $colorReport = $this->mergeColors($settings, $colors);
        $typographyReport = $this->mergeTypography($settings, $typography);

        update_post_meta($kitId, self::SETTINGS_META, $settings);
        $this->clearElementorCache();

        return ['kitId' => $kitId, 'colors' => $colorReport, 'typography' => $typographyReport];
    }

    /** @return array<string,mixed> */
    public function kitSettings(): array
    {
        $kitId = (int) get_option('elementor_active_kit');
        $settings = $kitId > 0 ? get_post_meta($kitId, self::SETTINGS_META, true) : [];
        return is_array($settings) ? $settings : [];
    }

    /**
     * Colour value (uppercase) to global id, so the transpiler can bind a widget to
     * "globals/colors?id=secondary" instead of hard-coding "#CD1C18".
     * @return array<string,string>
     */
    public function colorIndex(): array
    {
        $settings = $this->kitSettings();
        $index = [];
        foreach (['system_colors', 'custom_colors'] as $group) {
            foreach ((array) ($settings[$group] ?? []) as $entry) {
                if (!is_array($entry) || !is_string($entry['color'] ?? null) || !is_string($entry['_id'] ?? null)) {
                    continue;
                }
                // A palette often repeats a value under several roles (primary and
                // text can both be #260007). Keep the first, so system roles win
                // over later duplicates instead of the last one silently taking over.
                $index[self::colorKey($entry['color'])] ??= $entry['_id'];
            }
        }
        return $index;
    }

    /**
     * "family|size|weight" to global typography id, plus the preset title for reporting.
     * @return array<string,array{id:string,title:string}>
     */
    public function typographyIndex(): array
    {
        $settings = $this->kitSettings();
        $index = [];
        foreach (['system_typography', 'custom_typography'] as $group) {
            foreach ((array) ($settings[$group] ?? []) as $entry) {
                if (!is_array($entry) || !is_string($entry['_id'] ?? null)) {
                    continue;
                }
                $size = $entry['typography_font_size']['size'] ?? null;
                $family = $entry['typography_font_family'] ?? null;
                $weight = $entry['typography_font_weight'] ?? null;
                if ($size === null || $family === null || $weight === null) {
                    continue;
                }
                $index[self::typographyKey((string) $family, (float) $size, (string) $weight)] = ['id' => $entry['_id'], 'title' => (string) ($entry['title'] ?? $entry['_id'])];
            }
        }
        return $index;
    }

    /** Normalises "rgba(0, 0, 0, 0)" and "#FFF " so equal colours compare equal. */
    public static function colorKey(string $color): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($color)) ?? '');
    }

    public static function typographyKey(string $family, float $size, string $weight): string
    {
        return strtolower(trim($family)) . '|' . (string) round($size, 2) . '|' . trim($weight);
    }

    /**
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $colors
     * @return array<string,mixed>
     */
    private function mergeColors(array &$settings, array $colors): array
    {
        $custom = is_array($settings['custom_colors'] ?? null) ? $settings['custom_colors'] : [];
        $system = is_array($settings['system_colors'] ?? null) ? $settings['system_colors'] : [];
        $systemIds = $this->titleIndex($system);
        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($colors as $color) {
            $title = trim((string) ($color['name'] ?? ''));
            $value = strtoupper(trim((string) ($color['value'] ?? '')));
            if ($title === '' || $value === '') {
                continue;
            }
            $slug = $this->slug($title);
            // A colour already carrying a semantic role stays under the designer's control.
            if (isset($systemIds[$slug])) {
                $skipped[] = ['title' => $title, 'reason' => 'matches a system colour by name'];
                continue;
            }
            $index = $this->findIndex($custom, $slug);
            if ($index !== null) {
                if (strtoupper((string) ($custom[$index]['color'] ?? '')) !== $value) {
                    $custom[$index]['color'] = $value;
                    $updated[] = $title;
                }
                continue;
            }
            // Figma and Elementor rarely use the same names for the same colour
            // ("color/red/950" vs a custom dark-name label), so an identical value counts as
            // already present rather than as a new global to create.
            $existing = $this->findByValue(array_merge($system, $custom), $value);
            if ($existing !== null) {
                $skipped[] = ['title' => $title, 'reason' => 'same value already exists as "' . $existing . '"'];
                continue;
            }
            $custom[] = ['_id' => $slug, 'title' => $title, 'color' => $value];
            $created[] = $title;
        }

        $settings['custom_colors'] = array_values($custom);
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($custom)];
    }

    /**
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $typography
     * @return array<string,mixed>
     */
    private function mergeTypography(array &$settings, array $typography): array
    {
        $custom = is_array($settings['custom_typography'] ?? null) ? $settings['custom_typography'] : [];
        $system = is_array($settings['system_typography'] ?? null) ? $settings['system_typography'] : [];
        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($typography as $style) {
            $title = trim((string) ($style['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $slug = $this->slug($title);
            $fields = $this->typographyFields($style);
            $index = $this->findIndex($custom, $slug);
            if ($index === null) {
                // Figma token names ("typography/text/PlusJakartaSans/72") rarely match
                // curated Elementor titles ("Display / LG"), so an identical
                // family/size/weight counts as the same preset already existing.
                $existing = $this->findByTypography(array_merge($system, $custom), $fields);
                if ($existing !== null) {
                    $skipped[] = ['title' => $title, 'reason' => 'same values already exist as "' . $existing . '"'];
                    continue;
                }
                $custom[] = array_merge(['_id' => $slug, 'title' => $title], $fields);
                $created[] = $title;
                continue;
            }
            // Merge, so unmanaged keys already set in Elementor survive the sync.
            $before = $custom[$index];
            $custom[$index] = array_merge($before, $fields);
            if ($custom[$index] !== $before) {
                $updated[] = $title;
            }
        }

        $settings['custom_typography'] = array_values($custom);
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($custom)];
    }

    /**
     * @param array<mixed> $entries Untrusted stored kit entries.
     * @param array<string,mixed> $fields
     * Returns the title of a preset already carrying the same family, size and weight.
     */
    private function findByTypography(array $entries, array $fields): ?string
    {
        $size = $fields['typography_font_size']['size'] ?? null;
        $family = $fields['typography_font_family'] ?? null;
        $weight = $fields['typography_font_weight'] ?? null;
        if ($size === null || $family === null || $weight === null) {
            return null;
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (
                ($entry['typography_font_family'] ?? null) === $family
                && (string) ($entry['typography_font_weight'] ?? '') === (string) $weight
                && (float) ($entry['typography_font_size']['size'] ?? -1) === (float) $size
            ) {
                return (string) ($entry['title'] ?? $entry['_id'] ?? '');
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    private function typographyFields(array $style): array
    {
        // Elementor only applies a group control when its "_typography" switch is custom.
        $fields = ['typography_typography' => 'custom'];
        if (is_string($style['fontFamily'] ?? null) && $style['fontFamily'] !== '') {
            $fields['typography_font_family'] = $style['fontFamily'];
        }
        if (is_numeric($style['fontSize'] ?? null)) {
            $fields['typography_font_size'] = ['unit' => 'px', 'size' => (float) $style['fontSize'], 'sizes' => []];
        }
        if (is_string($style['fontWeight'] ?? null) && $style['fontWeight'] !== '') {
            $fields['typography_font_weight'] = $style['fontWeight'];
        }
        if (is_numeric($style['lineHeight'] ?? null)) {
            $fields['typography_line_height'] = ['unit' => (string) ($style['lineHeightUnit'] ?? 'px'), 'size' => (float) $style['lineHeight'], 'sizes' => []];
        }
        if (is_numeric($style['letterSpacing'] ?? null)) {
            $fields['typography_letter_spacing'] = ['unit' => 'px', 'size' => (float) $style['letterSpacing'], 'sizes' => []];
        }
        if (is_string($style['textTransform'] ?? null) && $style['textTransform'] !== '') {
            $fields['typography_text_transform'] = $style['textTransform'];
        }
        return $fields;
    }

    /** @param array<mixed> $entries Untrusted stored kit entries. @return array<string,int> */
    private function titleIndex(array $entries): array
    {
        $index = [];
        foreach ($entries as $position => $entry) {
            if (is_array($entry)) {
                $index[$this->slug((string) ($entry['title'] ?? ''))] = (int) $position;
            }
        }
        return $index;
    }

    /** @param array<mixed> $entries Returns the title already holding this colour. */
    private function findByValue(array $entries, string $value): ?string
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && strtoupper(trim((string) ($entry['color'] ?? ''))) === $value) {
                return (string) ($entry['title'] ?? $entry['_id'] ?? '');
            }
        }
        return null;
    }

    /** @param array<mixed> $entries Untrusted stored kit entries. */
    private function findIndex(array $entries, string $slug): ?int
    {
        foreach ($entries as $position => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string) ($entry['_id'] ?? '') === $slug || $this->slug((string) ($entry['title'] ?? '')) === $slug) {
                return (int) $position;
            }
        }
        return null;
    }

    /** "Display / LG" and "Display/LG" both become "display-lg". */
    private function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    private function clearElementorCache(): void
    {
        if (!class_exists('Elementor\\Plugin')) {
            return;
        }
        $plugin = \Elementor\Plugin::$instance ?? null;
        if (is_object($plugin) && isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
            $plugin->files_manager->clear_cache();
        }
    }
}
