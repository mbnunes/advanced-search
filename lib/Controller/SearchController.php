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
            // Pegar dados do corpo da requisição POST
            $params = $this->request->getParams();

            $debug = $this->searchService->debugFullTextSearch();

            // Extrair parâmetros com valores padrão
            $filename = isset($params['filename']) ? trim($params['filename']) : '';
            $tags = isset($params['tags']) && is_array($params['tags']) ? $params['tags'] : [];
            $tagOperator = isset($params['tagOperator']) ? $params['tagOperator'] : 'AND';
            $fileType = isset($params['fileType']) ? $params['fileType'] : '';
            $limit = isset($params['limit']) ? max(1, min(500, (int)$params['limit'])) : 100;
            $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
            $useFullTextSearch = isset($params['useFullTextSearch']) ? (bool)$params['useFullTextSearch'] : true; // MUDANÇA: Padrão true

            // Validar tagOperator
            if (!in_array($tagOperator, ['AND', 'OR'])) {
                $tagOperator = 'AND';
            }

            // Filtrar tags vazias
            $tags = array_filter($tags, function ($tag) {
                return !empty(trim($tag));
            });

            $fullTextAvailable = $this->searchService->isFullTextSearchAvailable();

            // LÓGICA OTIMIZADA: Sempre tentar FullTextSearch primeiro quando há busca por nome
            $actualSearchType = 'traditional';
            $searchMethod = 'traditional';

            if (!empty($filename)) {
                // Se tem busca por nome, decidir qual método usar
                if ($fullTextAvailable && $useFullTextSearch) {
                    // Tentar FullTextSearch primeiro
                    $results = $this->searchService->searchFilesWithFullText($filename, $tags, $tagOperator, $fileType, $limit, $offset);
                    $searchMethod = 'fulltext_attempted';

                    // Verificar se realmente usou FullTextSearch olhando o searchType dos resultados
                    if (!empty($results) && isset($results[0]['searchType']) && $results[0]['searchType'] === 'fulltext') {
                        $actualSearchType = 'fulltext';
                    } else {
                        $actualSearchType = 'traditional_fallback';
                    }
                } else {
                    // Usar busca tradicional diretamente
                    $results = $this->searchService->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
                    $searchMethod = 'traditional_direct';
                }
            } else {
                // Se não tem busca por nome (apenas tags ou tipo), usar sempre tradicional
                $results = $this->searchService->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
                $searchMethod = 'traditional_no_filename';
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
