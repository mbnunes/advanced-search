<?php
namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCA\AdvancedSearch\Service\SearchService;

class SearchController extends Controller {
    private $searchService;

    public function __construct($AppName, IRequest $request, SearchService $searchService) {
        parent::__construct($AppName, $request);
        $this->searchService = $searchService;
    }

    #[NoAdminRequired]
    public function search($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        try {
            // Validar parâmetros
            $limit = max(1, min(500, (int)$limit)); // Entre 1 e 500
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
}