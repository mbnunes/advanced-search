<?php
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'POST'],
        ['name' => 'search#getTags', 'url' => '/api/tags', 'verb' => 'GET'],
    ]
];