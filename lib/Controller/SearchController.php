<?php
namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCA\AdvancedSearch\Service\SearchService;
use OCP\SystemTag\ISystemTagManager;

class SearchController extends Controller {
    private $searchService;
    private $systemTagManager;

    public function __construct($AppName, IRequest $request, SearchService $searchService, ISystemTagManager $systemTagManager) {
        parent::__construct($AppName, $request);
        $this->searchService = $searchService;
        $this->systemTagManager = $systemTagManager;
    }

    #[NoAdminRequired]
    public function search($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        try {
            $limit = max(1, min(500, (int)$limit));
            $offset = max(0, (int)$offset);
            
            $results = $this->searchService->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
            
            return new JSONResponse([
                'success' => true, 
                'files' => $results,
                'count' => count($results),
                'limit' => $limit,
                'offset' => $offset
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false, 
                'message' => $e->getMessage()
            ]);
        }
    }

    #[NoAdminRequired]
    public function getTags() {
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
            ]);
        }
    }
}