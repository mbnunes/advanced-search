<?php

namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCA\AdvancedSearch\Service\SearchService;
use OCP\SystemTag\ISystemTagManager;

class SearchController extends Controller
{
    private $searchService;
    private $systemTagManager;

    public function __construct($AppName, IRequest $request, SearchService $searchService, ISystemTagManager $systemTagManager)
    {
        parent::__construct($AppName, $request);
        $this->searchService = $searchService;
        $this->systemTagManager = $systemTagManager;
    }

    #[NoAdminRequired]
    public function search()
    {
        try {
            $debug = true; // FORCE DEBUG
            $params = $this->request->getParams();
            
            // LOG DE DEBUG
            $logFile = '/tmp/search_debug.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "[$timestamp] Controller: search() called\n", FILE_APPEND);

            $debug = $this->searchService->debugFullTextSearch();

            // Extrair parâmetros com valores padrão
            $filename = isset($params['filename']) ? trim($params['filename']) : '';
            $tags = isset($params['tags']) && is_array($params['tags']) ? $params['tags'] : [];
            $tagOperator = isset($params['tagOperator']) ? $params['tagOperator'] : 'AND';
            $fileType = isset($params['fileType']) ? $params['fileType'] : '';
            $limit = isset($params['limit']) ? max(1, min(10000, (int)$params['limit'])) : 100;
            $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
            $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
            $useFullTextSearch = isset($params['useFullTextSearch']) ? (bool)$params['useFullTextSearch'] : true; // MUDANÇA: Padrão true
            $excludedExtensions = isset($params['excludedExtensions']) && is_array($params['excludedExtensions']) ? $params['excludedExtensions'] : [];

            // Validar tagOperator
            if (!in_array($tagOperator, ['AND', 'OR'])) {
                $tagOperator = 'AND';
            }

            // Filtrar tags vazias
            $tags = array_filter($tags, function ($tag) {
                return !empty(trim($tag));
            });

            $fullTextAvailable = $this->searchService->isFullTextSearchAvailable();

            // LÓGICA OTIMIZADA: Sempre tentar FullTextSearch primeiro
            $actualSearchType = 'traditional';
            $searchMethod = 'traditional';

            // LÓGICA FORÇADA: Sempre usar FullTextSearch (integração direta)
            // Ignoramos se $fullTextAvailable é true ou false, pois estamos usando conexão direta
            
            $results = $this->searchService->searchFilesWithFullText($filename, $tags, $tagOperator, $fileType, $limit, $offset, $excludedExtensions);
            $searchMethod = 'fulltext_forced';

            // Verificar se realmente usou FullTextSearch olhando o searchType dos resultados
            if (!empty($results) && isset($results[0]['searchType']) && ($results[0]['searchType'] === 'fulltext' || $results[0]['searchType'] === 'hybrid')) {
                $actualSearchType = $results[0]['searchType'];
            } else {
                $actualSearchType = 'traditional_fallback';
            }

            // Adicionar tempo de execução para debug
            $endTime = microtime(true);
            $executionTime = isset($startTime) ? ($endTime - $startTime) : null;

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
                    'requestedFullText' => $useFullTextSearch,
                    'hasFilename' => !empty($filename),
                    'hasTags' => !empty($tags),
                    'excludedExtensions' => $excludedExtensions,
                    'executionTime' => $executionTime
                ],
                'debug' => $debug  // INFORMAÇÕES DE DEBUG
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Adicionar para debug em desenvolvimento
            ], 500);
        }
    }

    #[NoAdminRequired]
    public function getTags()
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

    #[NoAdminRequired]
    #[NoCSRFRequired]  // ADICIONAR ESTA LINHA
    public function debug()
    {
        try {
            $debug = $this->searchService->debugFullTextSearch();

            return new JSONResponse([
                'success' => true,
                'debug' => $debug
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
