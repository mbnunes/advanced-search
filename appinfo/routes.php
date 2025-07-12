<?php
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'POST'],
        ['name' => 'search#searchWithFilters', 'url' => '/api/search/filters', 'verb' => 'GET'],
        ['name' => 'search#getTags', 'url' => '/api/tags', 'verb' => 'GET'],
    ]
];