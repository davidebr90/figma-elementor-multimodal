<?php
require __DIR__ . '/../../packages/wordpress-plugin/vendor/autoload.php';
$nodes = [];
foreach (['heading', 'text-editor', 'button', 'divider', 'spacer', 'container'] as $role) {
    $nodes[$role] = ['id' => $role, 'widget' => $role, 'children' => [], 'text' => ['characters' => "A & B <test> \\ path\nnext", 'fontSize' => 32], 'link' => 'https://example.com/?a=1&b=2',
        'layout' => ['padding' => ['top' => 12, 'right' => 0, 'bottom' => 8, 'left' => 4]],
        'style' => ['background' => '#ff5a3d', 'borderWidth' => 2, 'borderColor' => '#172b44', 'radius' => 6],
        'responsive' => ['mobile' => ['padding' => ['top' => 3]]]];
}
$nodes['container']['children'] = ['heading', 'text-editor', 'button', 'divider', 'spacer'];
echo (new FEM\Blocks\BlockRenderer())->render(['roots' => ['container'], 'nodes' => $nodes])['content'];
