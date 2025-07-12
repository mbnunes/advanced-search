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

            // ADICIONAR ESTA LINHA PARA VERIFICAR SE DEVE USAR FULL TEXT SEARCH
            $useFullTextSearch = isset($params['useFullTextSearch']) ? (bool)$params['useFullTextSearch'] : true;
            
            
            // Validar tagOperator
            if (!in_array($tagOperator, ['AND', 'OR'])) {
                $tagOperator = 'AND';
            }
            
            // Filtrar tags vazias
            $tags = array_filter($tags, function($tag) {
                return !empty(trim($tag));
            });
            
            // MODIFICAR ESTA PARTE PARA USAR O NOVO MÉTODO
            if ($useFullTextSearch && !empty($filename)) {
                // Usar full text search quando há busca por texto
                $results = $this->searchService->searchFilesWithFullText($filename, $tags, $tagOperator, $fileType, $limit, $offset);
            } else {
                // Usar busca tradicional
                $results = $this->searchService->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
            }

            // ADICIONAR INFORMAÇÃO SOBRE O TIPO DE BUSCA UTILIZADA
            $searchType = 'traditional';
            if ($useFullTextSearch && !empty($filename) && $this->searchService->isFullTextSearchAvailable()) {
                $searchType = 'fulltext';
            }

            return new JSONResponse([
                'success' => true, 
                'files' => $results,
                'count' => count($results),
                'limit' => $limit,
                'offset' => $offset,
                'searchType' => $searchType, // ADICIONAR ESTA LINHA
                'fullTextSearchAvailable' => $this->searchService->isFullTextSearchAvailable() // ADICIONAR ESTA LINHA
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false, 
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ADICIONAR ESTE MÉTODO NOVO
    #[NoAdminRequired]
    public function searchInfo() {
        try {
            return new JSONResponse([
                'success' => true,
                'fullTextSearchAvailable' => $this->searchService->isFullTextSearchAvailable()
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
        // ... código existente permanece igual
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