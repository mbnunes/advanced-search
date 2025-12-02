<?php

namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;
use OCP\Files\FileInfo;
use OCP\App\IAppManager;
use OCP\FullTextSearch\IFullTextSearchManager;

class SearchService
{
    private $rootFolder;
    private $userSession;
    private $systemTagManager;
    private $systemTagObjectMapper;
    private $fullTextSearchManager;
    private $appManager;
    private $lastError = '';

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagObjectMapper,
        IAppManager $appManager,
                ?IFullTextSearchManager $fullTextSearchManager = null // Adicionar como opcional
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->systemTagManager = $systemTagManager;
        $this->systemTagObjectMapper = $systemTagObjectMapper;
        $this->appManager = $appManager;
                $this->fullTextSearchManager = $fullTextSearchManager;
        $this->log('SearchService initialized. Manager: ' . ($fullTextSearchManager ? 'yes' : 'no'));
    }

    private function log($message) {
        $logFile = '/tmp/search_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] Service: $message\n", FILE_APPEND);
    }

    private function checkFulltextSearchAvailable()
    {
        if (!$this->appManager->isEnabledForUser('fulltextsearch')) {
            throw new \Exception('Fulltextsearch app não está habilitado');
        }
        return true;
    }

    // MANTER SUA FUNÇÃO ORIGINAL searchFiles EXATAMENTE COMO ESTAVA
    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0)
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $results = [];

        // Buscar arquivos
        if (!empty($filename)) {
            // Certificar que o filename não está vazio antes de chamar search()
            $searchTerm = trim($filename);
            if (strlen($searchTerm) > 0) {
                error_log('[AdvancedSearch] Executing traditional search for: ' . $searchTerm);
                $searchResults = $userFolder->search($searchTerm);
            } else {
                $searchResults = [];
            }
        } else {
            // Se não tem nome, buscar por outros critérios
            $searchResults = $this->searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator);
        }

        // Otimização 1: Filtrar IDs por tags antecipadamente se houver tags
        $allowedFileIds = null;
        if (!empty($tags)) {
            $allowedFileIds = $this->getFileIdsByTags($tags, $tagOperator);
            // Se buscou por tags e não achou nada, já retorna vazio
            if (empty($allowedFileIds)) {
                return [];
            }
            // Converter para chaves para busca rápida O(1)
            $allowedFileIds = array_flip($allowedFileIds);
        }

        // Filtrar e processar resultados
        $filteredResults = [];
        $count = 0;
        $skipped = 0;

        foreach ($searchResults as $file) {
            // Verificar se é arquivo (não pasta)
            if ($file->getType() !== FileInfo::TYPE_FILE) {
                continue;
            }

            // Filtrar por tipo de arquivo
            if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) {
                continue;
            }

            // Filtrar por tags (usando o array pré-calculado)
            if ($allowedFileIds !== null && !isset($allowedFileIds[$file->getId()])) {
                continue;
            }

            // Otimização 2: Paginação manual com Early Exit
            // Se ainda não chegamos no offset, pular
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            // Adicionar aos resultados
            $filteredResults[] = $file;
            $count++;

            // Se já pegamos o limite, parar
            if ($count >= $limit) {
                break;
            }
        }

        // Otimização 3: Buscar tags em lote para os arquivos da página atual
        // SE o limite for muito alto (ex: > 200), provavelmente é uma contagem ou exportação
        // Nesses casos, pular o carregamento de tags para performance
        $tagsByFileId = [];
        if ($limit <= 200) {
            $fileIds = array_map(function($f) { return $f->getId(); }, $filteredResults);
            $tagsByFileId = $this->getTagsForFiles($fileIds);
        }

        // Formatar resultados
        foreach ($filteredResults as $file) {
            $fileTags = isset($tagsByFileId[$file->getId()]) ? $tagsByFileId[$file->getId()] : [];
            $results[] = $this->formatFileResult($file, $fileTags);
        }

        return $results;
    }

    public function searchFilesWithFullText($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        $this->log("searchFilesWithFullText START. Filename: '$filename'");
        
        // Se full text search não estiver disponível, usar método tradicional
        // AGORA: Tentar conexão direta mesmo sem manager
        // if (!$this->fullTextSearchManager) { ... }

        try {
            $this->log("Preparing Direct SearchRequest...");
            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \Exception('User not logged in');
            }

            // CHAMADA DIRETA AO ELASTICSEARCH
            $documents = $this->searchDirectElasticsearch($filename, $limit, $offset);
            
            $results = [];
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            
            $candidates = [];
            $fileIds = [];
            
            foreach ($documents as $hit) {
                try {
                    // O ID vem como "files:12345", precisamos extrair o número
                    $elasticId = $hit['_id'];
                    $fileId = 0;
                    
                    if (strpos($elasticId, 'files:') === 0) {
                        $fileId = (int) substr($elasticId, 6);
                    } else {
                        $fileId = (int) $elasticId;
                    }

                    if ($fileId === 0) continue;

                    $nodes = $userFolder->getById($fileId);
                    
                    if (!empty($nodes) && $nodes[0]->getType() === FileInfo::TYPE_FILE) {
                        $fileInfo = $nodes[0];
                        $candidates[] = [
                            'file' => $fileInfo,
                            'score' => $hit['_score'],
                            'excerpt' => '' // Excerpt não vem fácil no direct hit sem highlight
                        ];
                        $fileIds[] = $fileId;
                    }
                } catch (\Exception $e) {
                    $this->log("Error processing hit: " . $e->getMessage());
                    continue;
                }
            }
            
            $this->log("Candidates found: " . count($candidates));

            $tagsByFileId = [];
            if ($limit <= 200) {
                $this->log("Fetching tags for " . count($fileIds) . " files...");
                $tagsByFileId = $this->getTagsForFiles($fileIds);
            }
            
            $this->log("Formatting results...");
            foreach ($candidates as $candidate) {
                $fileInfo = $candidate['file'];
                $fileId = $fileInfo->getId();
                $fileTags = isset($tagsByFileId[$fileId]) ? $tagsByFileId[$fileId] : [];
                
                if (!empty($fileType) && !$this->matchesFileType($fileInfo, $fileType)) {
                    continue;
                }
                
                if (!empty($tags) && !$this->tagsMatch($fileTags, $tags, $tagOperator)) {
                    continue;
                }
                
                $result = $this->formatFileResult($fileInfo, $fileTags);
                $result['searchType'] = 'fulltext';
                $result['score'] = $candidate['score'];
                $result['excerpt'] = $candidate['excerpt'];
                $results[] = $result;
            }
            
            $this->log("Returning " . count($results) . " results. END.");
            return $results;
            
        } catch (\Exception $e) {
            $this->lastError = "EXCEPTION in searchFilesWithFullText: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString();
            $this->log($this->lastError);
            return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        }
    }

    private function getFileInfoFromDocument($document, $userFolder)
    {
        $fileId = $document->getId();

        if ($fileId) {
            try {
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    return $nodes[0];
                }
            } catch (\Exception $e) {
                // Continuar
            }
        }

        return null;
    }

    public function isFullTextSearchAvailable()
    {
        if (!$this->fullTextSearchManager) {
            return false;
        }

        try {
            // Verificar se o serviço está disponível
            $isAvailable = $this->fullTextSearchManager->isAvailable();

            // Log para debug
            error_log('FullTextSearch isAvailable(): ' . ($isAvailable ? 'true' : 'false'));

            return $isAvailable;
        } catch (\Exception $e) {
            error_log('Error checking FullTextSearch availability: ' . $e->getMessage());
            return false;
        }
    }

    // MANTER TODAS AS SUAS FUNÇÕES ORIGINAIS ABAIXO SEM ALTERAÇÃO

    private function searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator)
    {
        // Se só temos busca por tags, usar método específico
        if (!empty($tags) && empty($fileType)) {
            return $this->searchByTagsOnly($userFolder, $tags, $tagOperator);
        }

        // Se só temos busca por tipo de arquivo, buscar por extensão comum
        if (!empty($fileType) && empty($tags)) {
            return $this->searchByFileTypeOnly($userFolder, $fileType);
        }

        // Busca geral - pegar arquivos recentes como fallback
        try {
            return $userFolder->getRecent(1000);
        } catch (\Exception $e) {
            // Se getRecent não funcionar, retornar array vazio
            return [];
        }
    }

    private function searchByTagsOnly($userFolder, $tags, $tagOperator)
    {
        $fileIds = $this->getFileIdsByTags($tags, $tagOperator);
        $files = [];

        foreach ($fileIds as $fileId) {
            try {
                $fileNodes = $userFolder->getById($fileId);
                if (!empty($fileNodes)) {
                    $files[] = $fileNodes[0];
                }
            } catch (\Exception $e) {
                // Arquivo não encontrado ou sem permissão
                continue;
            }
        }

        return $files;
    }

    private function searchByFileTypeOnly($userFolder, $fileType)
    {
        $extensions = $this->getExtensionsForFileType($fileType);
        $files = [];

        foreach ($extensions as $extension) {
            try {
                // Buscar com ponto antes da extensão
                $searchResults = $userFolder->search('.' . $extension);
                foreach ($searchResults as $result) {
                    // Verificar se realmente termina com a extensão
                    if (strtolower(pathinfo($result->getName(), PATHINFO_EXTENSION)) === $extension) {
                        $files[] = $result;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $files;
    }

    // MÉTODO CORRIGIDO PARA NEXTCLOUD 31
    private function getFileIdsByTags($tags, $operator)
    {
        $fileIds = [];

        try {
            // Coletar IDs das tags
            $tagIds = [];
            foreach ($tags as $tagName) {
                $tagId = $this->getTagIdByName($tagName);
                if ($tagId) {
                    $tagIds[] = $tagId;
                } else if ($operator === 'AND') {
                    // Se operador é AND e uma tag não existe, retornar vazio
                    return [];
                }
            }

            if (empty($tagIds)) {
                return [];
            }

            if ($operator === 'AND') {
                // Para AND, usar getObjectIdsForTags que retorna apenas objetos com TODAS as tags
                $fileIds = $this->systemTagObjectMapper->getObjectIdsForTags($tagIds, 'files');
            } else { // OR
                // Para OR, buscar objetos para cada tag e fazer união
                $allFileIds = [];
                foreach ($tagIds as $tagId) {
                    $tagFileIds = $this->systemTagObjectMapper->getObjectIdsForTags([$tagId], 'files');
                    $allFileIds = array_merge($allFileIds, $tagFileIds);
                }
                $fileIds = array_unique($allFileIds);
            }
        } catch (\Exception $e) {
            return [];
        }

        return $fileIds;
    }

    private function getTagIdByName($tagName)
    {
        try {
            // Buscar todas as tags do sistema
            $allTags = $this->systemTagManager->getAllTags();

            foreach ($allTags as $tag) {
                if ($tag->getName() === $tagName) {
                    return $tag->getId();
                }
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    private function matchesFileType($file, $fileType)
    {
        $mimetype = $file->getMimetype();
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

        switch ($fileType) {
            case 'image':
                return strpos($mimetype, 'image/') === 0;

            case 'document':
                return in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'txt']) ||
                    strpos($mimetype, 'text/') === 0 ||
                    strpos($mimetype, 'application/msword') === 0 ||
                    strpos($mimetype, 'application/vnd.openxmlformats-officedocument.wordprocessingml') === 0 ||
                    strpos($mimetype, 'application/vnd.oasis.opendocument.text') === 0;

            case 'video':
                return strpos($mimetype, 'video/') === 0;

            case 'audio':
                return strpos($mimetype, 'audio/') === 0;

            case 'pdf':
                return $mimetype === 'application/pdf';

            default:
                return true;
        }
    }

    private function getExtensionsForFileType($fileType)
    {
        switch ($fileType) {
            case 'image':
                return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];

            case 'document':
                return ['doc', 'docx', 'odt', 'txt', 'rtf', 'md'];

            case 'video':
                return ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'];

            case 'audio':
                return ['mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a'];

            case 'pdf':
                return ['pdf'];

            default:
                return [];
        }
    }

    private function fileMatchesTags($fileId, $tags, $tagOperator)
    {
        $fileTags = $this->getFileTags($fileId);
        return $this->tagsMatch($fileTags, $tags, $tagOperator);
    }

    private function tagsMatch($fileTags, $requiredTags, $tagOperator)
    {
        $fileTagNames = array_column($fileTags, 'name');
        $matches = array_intersect($requiredTags, $fileTagNames);

        if ($tagOperator === 'AND') {
            return count($matches) === count($requiredTags);
        } else { // OR
            return count($matches) > 0;
        }
    }

    private function formatFileResult($file, $tags = null)
    {
        // Se tags não foram passadas, buscar (fallback para compatibilidade)
        if ($tags === null) {
            $tags = $this->getFileTags($file->getId());
        }

        return [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'path' => $file->getPath(),
            'type' => $file->getType(),
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
            'mimetype' => $file->getMimetype(),
            'tags' => $tags,
            'searchType' => 'traditional'
        ];
    }

    // NOVA FUNÇÃO: Buscar tags em lote
    private function getTagsForFiles($fileIds)
    {
        if (empty($fileIds)) {
            return [];
        }

        try {
            // Buscar mapeamento objeto -> tags para todos os arquivos
            $tagsByObjectId = $this->systemTagObjectMapper->getTagIdsForObjects($fileIds, 'files');
            
            // Coletar todos os IDs de tags únicos necessários
            $allTagIds = [];
            foreach ($tagsByObjectId as $objectId => $tagIds) {
                foreach ($tagIds as $tagId) {
                    $allTagIds[$tagId] = $tagId;
                }
            }

            if (empty($allTagIds)) {
                return [];
            }

            // Buscar informações das tags
            $tagsInfo = $this->systemTagManager->getTagsByIds(array_values($allTagIds));
            $tagsMap = [];
            foreach ($tagsInfo as $tag) {
                $tagsMap[$tag->getId()] = [
                    'id' => $tag->getId(),
                    'name' => $tag->getName(),
                    'color' => $tag->isUserAssignable() ? 'blue' : 'red'
                ];
            }

            // Montar resultado final mapeado por fileId
            $result = [];
            foreach ($fileIds as $fileId) {
                $result[$fileId] = [];
                if (isset($tagsByObjectId[$fileId])) {
                    foreach ($tagsByObjectId[$fileId] as $tagId) {
                        if (isset($tagsMap[$tagId])) {
                            $result[$fileId][] = $tagsMap[$tagId];
                        }
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            error_log('Error in getTagsForFiles: ' . $e->getMessage());
            return [];
        }
    }

    // MÉTODO TAMBÉM CORRIGIDO PARA USAR getTagIdsForObjects
    private function getFileTags($fileId)
    {
        $tags = $this->getTagsForFiles([$fileId]);
        return isset($tags[$fileId]) ? $tags[$fileId] : [];
    }

    // Funcao de DEBUG
    public function debugFullTextSearch()
    {
        $debug = [];

        // Verificar se a classe existe
        $debug['class_exists'] = class_exists('\OCP\FullTextSearch\IFullTextSearchManager');

        // Verificar se o manager foi injetado
        $debug['manager_exists'] = $this->fullTextSearchManager !== null;
        
        // Verificar erro capturado
        $debug['last_error'] = $this->lastError;
        
        // Verificar CURL
        $debug['curl_exists'] = function_exists('curl_init');
        $debug['curl_version'] = function_exists('curl_version') ? curl_version() : 'N/A';

        if ($this->fullTextSearchManager) {
            try {
                $debug['is_available'] = $this->fullTextSearchManager->isAvailable();
            } catch (\Exception $e) {
                $debug['is_available_error'] = $e->getMessage();
            }

            try {
                // Tentar listar provedores
                $debug['providers'] = method_exists($this->fullTextSearchManager, 'getProviders')
                    ? $this->fullTextSearchManager->getProviders()
                    : 'method_not_exists';
            } catch (\Exception $e) {
                $debug['providers_error'] = $e->getMessage();
            }
        }

        return $debug;
    }
    private function searchDirectElasticsearch($term, $limit, $offset)
    {
        $url = 'http://187.45.162.16:9200/cob2023/_search';
        
        // Construir a query
        // Usar wildcard para busca parcial (ex: *BASQ*)
        // Buscar no título e conteúdo
        $query = [
            'from' => $offset,
            'size' => $limit,
            'query' => [
                'query_string' => [
                    'query' => '*' . $term . '*',
                    'fields' => ['title', 'content'],
                    'default_operator' => 'AND'
                ]
            ]
        ];

        $payload = json_encode($query);

        $this->log("Direct Elastic Request to $url: $payload");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout curto para não travar

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log("Direct Elastic CURL Error: $error");
            throw new \Exception("Elasticsearch connection failed: $error");
        }

        if ($httpCode !== 200) {
            $this->log("Direct Elastic HTTP Error $httpCode: $response");
            throw new \Exception("Elasticsearch returned HTTP $httpCode");
        }

        $data = json_decode($response, true);
        
        if (!isset($data['hits']['hits'])) {
            $this->log("Direct Elastic: Invalid response format");
            return [];
        }

        $this->log("Direct Elastic: Found " . count($data['hits']['hits']) . " hits (Total: " . $data['hits']['total']['value'] . ")");

        return $data['hits']['hits'];
    }
}
