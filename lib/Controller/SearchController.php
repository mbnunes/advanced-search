<?php

namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCA\AdvancedSearch\Service\SearchService;
use OCP\ILogger;

class SearchController extends Controller
{
    private $searchService;
    private $systemTagManager;
    private $logger;

    public function __construct(
        string $AppName,
        IRequest $request,
        SearchService $searchService,
        ISystemTagManager $systemTagManager,
        ILogger $logger
    ) {
        parent::__construct($AppName, $request);
        $this->searchService = $searchService;
        $this->systemTagManager = $systemTagManager;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    public function search(): JSONResponse
    {
        try {
            $params = $this->request->getParams();

            $query = isset($params['query']) ? trim($params['query']) : '';
            $fileType = isset($params['fileType']) ? $params['fileType'] : '';
            $limit = isset($params['limit']) ? max(1, min(500, (int)$params['limit'])) : 100;
            $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
            $useFullTextSearch = isset($params['useFullTextSearch']) ? (bool)$params['useFullTextSearch'] : true;

            $fullTextAvailable = $this->searchService->isFullTextSearchAvailable();
            $searchMethod = 'traditional';
            $actualSearchType = 'traditional';

            if (!empty($query) && $fullTextAvailable && $useFullTextSearch) {
                $results = $this->searchService->searchFilesWithFullText($query, [], 'AND', $fileType, $limit, $offset);
                
                if (!empty($results) && isset($results[0]['searchType']) && $results[0]['searchType'] === 'fulltext') {
                    $actualSearchType = 'fulltext';
                    $searchMethod = 'fulltext';
                } else {
                    $searchMethod = 'traditional_fallback';
                }
            }

            if ($actualSearchType === 'traditional') {
                // Fallback: parse query for tags
                $results = $this->searchService->searchFiles($query, [], 'AND', $fileType, $limit, $offset);
            }

            return new JSONResponse([
                'success' => true,
                'files' => $results,
                'count' => count($results),
                'limit' => $limit,
                'offset' => $offset,
                'searchInfo' => [
                    'actualSearchType' => $actualSearchType,
                    'searchMethod' => $searchMethod,
                    'fullTextSearchAvailable' => $fullTextAvailable,
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Advanced Search Error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString(), ['app' => 'advancedsearch']);
            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage() . ' (See nextcloud.log for details)'
            ], 500);
        }
    }

    #[NoAdminRequired]
    public function getTags(): JSONResponse
    {
        try {
            $allTags = $this->systemTagManager->getAllTags();
            $tagNames = [];

            foreach ($allTags as $tag) {
                if ($tag->isUserAssignable()) {
                    $tagNames[] = $tag->getName();
                }
            }

            return new JSONResponse([
                'success' => true,
                'tags' => $tagNames
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
