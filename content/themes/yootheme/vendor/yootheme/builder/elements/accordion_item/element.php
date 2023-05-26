<?php

namespace YOOtheme;

return [
    'transforms' => [
        'render' => function ($node, $params) {
            // Display
            foreach (['image', 'link'] as $key) {
                if (!$params['parent']->props["show_{$key}"]) {
                    $node->props[$key] = '';
                }
            }

            // Don't render element if content fields are empty
            return $node->props['title'] != '' &&
                ($node->props['content'] != '' || $node->props['image'] || $node->props['link']);
        },
    ],
];