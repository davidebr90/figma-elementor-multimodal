<?php

declare(strict_types=1);

$attributes = is_array($attributes ?? null) ? $attributes : [];
$class_name = '';
if (isset($attributes['className']) && is_string($attributes['className'])) {
    $class_name = implode(' ', array_filter(array_map('sanitize_html_class', preg_split('/\s+/', $attributes['className']) ?: [])));
}
$fem_id = isset($attributes['femId']) && is_string($attributes['femId']) ? sanitize_text_field($attributes['femId']) : '';
$schema_version = isset($attributes['schemaVersion']) && is_string($attributes['schemaVersion']) ? sanitize_text_field($attributes['schemaVersion']) : '1.0.0';
$source_hash = isset($attributes['sourceHash']) && is_string($attributes['sourceHash']) && preg_match('/^[a-f0-9]{64}$/', $attributes['sourceHash']) === 1 ? $attributes['sourceHash'] : '';
$classes = trim('fem-scene ' . $class_name);
$data = ' data-fem-schema="' . esc_attr($schema_version) . '"' . ($fem_id !== '' ? ' data-fem-id="' . esc_attr($fem_id) . '"' : '') . ($source_hash !== '' ? ' data-fem-source-hash="' . esc_attr($source_hash) . '"' : '');
echo '<div class="' . esc_attr($classes) . '"' . $data . '>' . ($content ?? '') . '</div>';
