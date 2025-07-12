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
    public function search() {
        try {
            // Pegar dados do corpo da requisição POST
            $params = $this->request->getParams();
            
            // Extrair parâmetros com valores padrão
            $filename = isset($params['filename']) ? trim($params['filename']) : '';
            $tags = isset($params['tags']) && is_array($params['tags']) ? $params['tags'] : [];
            $tagOperator = isset($params['tagOperator']) ? $params['tagOperator'] : 'AND';
            $fileType = isset($params['fileType']) ? $params['fileType'] : '';
            $limit = isset($params['limit']) ? max(1, min(500, (int)$params['limit'])) : 100;
            $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
            $useFullTextSearch = isset($params['useFullTextSearch']) ? (bool)$params['useFullTextSearch'] : false;
            
            // Validar tagOperator
            if (!in_array($tagOperator, ['AND', 'OR'])) {
                $tagOperator = 'AND';
            }
            
            // Filtrar tags vazias
            $tags = array_filter($tags, function($tag) {
                return !empty(trim($tag));
            });

            $fullTextAvailable = $this->searchService->isFullTextSearchAvailable();
            
            // Escolher método de busca
            if ($useFullTextSearch && !empty($filename) && $fullTextAvailable) {
                $results = $this->searchService->searchFilesWithFullText($filename, $tags, $tagOperator, $fileType, $limit, $offset);
                $actualSearchType = 'fulltext';
            } else {
                $results = $this->searchService->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
                $actualSearchType = 'traditional';
            }
            
            return new JSONResponse([
                'success' => true, 
                'files' => $results,
                'count' => count($results),
                'limit' => $limit,
                'offset' => $offset,
                'searchType' => $actualSearchType,
                'fullTextSearchAvailable' => $fullTextAvailable,
                'requestedFullText' => $useFullTextSearch,
                'hasFilename' => !empty($filename)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false, 
                'message' => $e->getMessage()
            ], 500);
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
            ], 500);
        }
    }
}