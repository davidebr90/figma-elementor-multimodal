<?php

declare(strict_types=1);

$attributes = is_array($attributes ?? null) ? $attributes : [];
$class_name = '';
if (isset($attributes['className']) && is_string($attributes['className'])) {
    $class_name = implode(' ', array_filter(array_map('sanitize_html_class', preg_split('/\s+/', $attributes['className']) ?: [])));
}
$fem_id = isset($attributes['femId']) && is_string($attributes['femId']) ? sanitize_text_field($attributes['femId']) : '';
$classes = trim('fem-scene ' . $class_name);
echo '<div class="' . esc_attr($classes) . '"' . ($fem_id !== '' ? ' data-fem-id="' . esc_attr($fem_id) . '"' : '') . '>' . ($content ?? '') . '</div>';
