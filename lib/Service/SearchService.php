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
    private $internalLogs = [];

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
        $msgWithVersion = "[v" . date('His') . "] " . $message;
        $this->internalLogs[] = $msgWithVersion; // Store for frontend
        
        // Log to TMP file (safer permissions)
        $logFile = '/tmp/advanced_search.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$timestamp] $msgWithVersion\n", FILE_APPEND);

        try {
            \OC::$server->getLogger()->error("[AdvancedSearch] $message", ['app' => 'advanced_search']);
        } catch (\Throwable $e) {
            // Fallback
            error_log("[AdvancedSearch] $message");
        }
    }
    
    public function getLogs() {
        return $this->internalLogs;
    }

    private function checkFulltextSearchAvailable()
    {
        if (!$this->appManager->isEnabledForUser('fulltextsearch')) {
            throw new \Exception('Fulltextsearch app não está habilitado');
        }
        return true;
    }

    // MANTER SUA FUNÇÃO ORIGINAL searchFiles COM LÓGICA UNIVERSAL
    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0, $excludedExtensions = [])
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $results = [];

        // Lógica Universal: Se temos um termo de busca ($filename), ele deve buscar em Nome OU Tags
        // Se também temos $tags explícitas (vindas do filtro #), elas continuam sendo obrigatórias (AND)
        
        $universalFileIds = null;

        if (!empty($filename)) {
            $searchTerm = trim($filename);
            if (strlen($searchTerm) > 0) {
                // Tokenizar a busca (separar por espaços)
                // Ex: "GINASTICA FLAVIA" -> ["GINASTICA", "FLAVIA"]
                // O arquivo deve dar match em TODOS os tokens (em nome OU tag)
                $tokens = preg_split('/\s+/', $searchTerm, -1, PREG_SPLIT_NO_EMPTY);
                
                foreach ($tokens as $token) {
                    // 1. Buscar arquivos com esse token no nome
                    $nameMatches = $userFolder->search($token);
                    $nameFileIds = [];
                    foreach ($nameMatches as $node) {
                        if ($node->getType() === FileInfo::TYPE_FILE) {
                            $nameFileIds[] = $node->getId();
                        }
                    }

                    // 2. Buscar arquivos com tags que contenham esse token
                    $tagFileIds = $this->getFileIdsByTagToken($token);

                    // União dos dois conjuntos (Nome U Tag) para este token
                    $tokenFileIds = array_unique(array_merge($nameFileIds, $tagFileIds));

                    // Interseção com os resultados anteriores (AND entre tokens)
                    if ($universalFileIds === null) {
                        $universalFileIds = $tokenFileIds;
                    } else {
                        $universalFileIds = array_intersect($universalFileIds, $tokenFileIds);
                    }

                    // Se em algum momento a interseção for vazia, não há resultados
                    if (empty($universalFileIds)) {
                        break;
                    }
                }
            }
        }

        // Se não houve busca por nome (universal), $universalFileIds é null.
        // Se houve e não achou nada, é [].

        // Otimização 1: Filtrar IDs por tags EXPLÍCITAS (#) se houver
        $explicitTagFileIds = null;
        if (!empty($tags)) {
            $explicitTagFileIds = $this->getFileIdsByTags($tags, $tagOperator);
            if (empty($explicitTagFileIds)) {
                return []; // Tags explícitas não encontraram nada
            }
        }

        // Combinar Universal Search com Tags Explícitas
        $finalAllowedIds = null;

        if ($universalFileIds !== null && $explicitTagFileIds !== null) {
            // Tem que satisfazer ambos
            $finalAllowedIds = array_intersect($universalFileIds, $explicitTagFileIds);
        } elseif ($universalFileIds !== null) {
            $finalAllowedIds = $universalFileIds;
        } elseif ($explicitTagFileIds !== null) {
            $finalAllowedIds = $explicitTagFileIds;
        }

        // Se $finalAllowedIds for vazio array (e não null), retorna vazio
        if ($finalAllowedIds !== null && empty($finalAllowedIds)) {
            return [];
        }

        // Se $finalAllowedIds for null, significa que não tem filtro de nome nem de tag.
        // Nesse caso, se tiver fileType, buscamos por tipo. Se não, retornamos recentes ou nada.
        
        $candidates = [];

        if ($finalAllowedIds !== null) {
            // Buscar os objetos Node para os IDs encontrados
            // Converter para chaves para busca rápida
            $finalAllowedIdsFlip = array_flip($finalAllowedIds);
            
            // Precisamos carregar os arquivos. 
            // Se forem muitos, isso pode ser pesado. Mas search() do userFolder também carrega.
            // Vamos iterar os IDs e carregar.
            
            foreach ($finalAllowedIds as $fileId) {
                try {
                    $nodes = $userFolder->getById($fileId);
                    if (!empty($nodes)) {
                        $candidates[] = $nodes[0];
                    }
                } catch (\Exception $e) { continue; }
            }
        } else {
            // Sem filtros de nome/tag. Verificar FileType.
            if (!empty($fileType)) {
                $candidates = $this->searchByFileTypeOnly($userFolder, $fileType);
            } else {
                // Sem nenhum filtro: retornar recentes
                try {
                    $candidates = $userFolder->getRecent(1000);
                } catch (\Exception $e) { $candidates = []; }
            }
        }

        // Filtrar e processar resultados (Paginação, FileType, Ocultos)
        $filteredResults = [];
        $count = 0;
        $skipped = 0;

        foreach ($candidates as $file) {
            // Verificar se é arquivo
            if ($file->getType() !== FileInfo::TYPE_FILE) continue;

            // Ignorar ocultos
            if (strpos($file->getName(), '.') === 0) continue;

            // Filtrar por tipo (se já não foi feito)
            if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) continue;

            // Filtrar por extensões excluídas
            if (!empty($excludedExtensions)) {
                $ext = '.' . strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
                if (in_array($ext, $excludedExtensions)) continue;
            }

            // Paginação
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            $filteredResults[] = $file;
            $count++;

            if ($count >= $limit) break;
        }

        // Carregar tags em lote
        $tagsByFileId = [];
        if ($limit <= 200) {
            $fileIds = array_map(function($f) { return $f->getId(); }, $filteredResults);
            $tagsByFileId = $this->getTagsForFiles($fileIds);
        }

        foreach ($filteredResults as $file) {
            $fileTags = isset($tagsByFileId[$file->getId()]) ? $tagsByFileId[$file->getId()] : [];
            $results[] = $this->formatFileResult($file, $fileTags);
        }

        return $results;
    }

    // Helper para buscar IDs de arquivos que tenham tags contendo um token
    private function getFileIdsByTagToken($token) {
        try {
            $allTags = $this->systemTagManager->getAllTags();
            $matchingTagIds = [];
            
            // Busca case-insensitive parcial nas tags (e fuzzy)
            foreach ($allTags as $tag) {
                $tagName = $tag->getName();
                
                // 1. Partial Match (Substring)
                if (stripos($tagName, $token) !== false) {
                    $matchingTagIds[] = $tag->getId();
                    continue;
                }
                
                // 2. Fuzzy Match (Levenshtein)
                // Usar apenas para tokens de tamanho razoável (> 3) para evitar matches ruins
                if (strlen($token) > 3) {
                    $distance = levenshtein(strtoupper($tagName), strtoupper($token));
                    // Permitir 1 erro para palavras curtas (4-5), 2 para longas (>5)
                    $limit = strlen($token) > 5 ? 2 : 1;
                    if ($distance <= $limit) {
                        $this->log("DEBUG: Fuzzy match for tag '$token' -> '$tagName' (Dist: $distance)");
                        $matchingTagIds[] = $tag->getId();
                    }
                }
            }

            if (empty($matchingTagIds)) {
                return [];
            }

            // Buscar objetos com essas tags (OR - qualquer uma das tags que deu match)
            // getObjectIdsForTags com array de tags retorna objetos que tem TODAS as tags?
            // Não, a documentação/implementação padrão do Nextcloud para getObjectIdsForTags
            // geralmente faz um AND se passarmos várias tags.
            // Para fazer OR (qualquer tag que contenha o token), precisamos iterar ou usar lógica específica.
            
            // Vamos assumir que precisamos fazer OR aqui: se a tag é "FLAVIA" ou "FLAVIA ANDRADE", ambas servem para o token "FLAVIA".
            
            $fileIds = [];
            // Fazer em lotes ou um por um? O mapper pode não suportar OR nativo facilmente.
            // Vamos buscar um por um e unir, é mais seguro.
            foreach ($matchingTagIds as $tagId) {
                $ids = $this->systemTagObjectMapper->getObjectIdsForTags([$tagId], 'files');
                foreach ($ids as $id) {
                    $fileIds[$id] = $id; // Usar chave para evitar duplicatas
                }
            }
            
            return array_values($fileIds);

        } catch (\Exception $e) {
            return [];
        }
    }

    public function searchFilesWithFullText($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0, $excludedExtensions = []) {
        $this->log("searchFilesWithFullText HYBRID START. Filename: '$filename'");
        
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \Exception('User not logged in');
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // LÓGICA HÍBRIDA:
            // 1. Tokenizar a busca.
            // 2. Para cada token:
            //    a. Buscar IDs no Elasticsearch (Title/Content)
            //    b. Buscar IDs no MySQL (Tags)
            //    c. Unir IDs (OR)
            // 3. Interseção dos resultados de cada token (AND)
            
            $finalFileIds = null;

            if (!empty($filename)) {
                $tokens = preg_split('/\s+/', trim($filename), -1, PREG_SPLIT_NO_EMPTY);
                
                if ($tokens === false) {
                    $this->log("Error: preg_split failed for filename: $filename");
                    $tokens = [];
                }

                foreach ($tokens as $token) {
                    $this->log("DEBUG: Processing token: '$token'");
                    // a. ES (Title/Content)
                    $esIds = $this->getIdsFromElasticsearch($token);
                    $this->log("DEBUG: ES IDs for '$token': " . count($esIds));
                    
                    // b. MySQL (Tags)
                    $dbIds = $this->getFileIdsByTagToken($token);
                    $this->log("DEBUG: DB IDs for '$token': " . count($dbIds));
                    
                    // c. União
                    $tokenIds = array_unique(array_merge($esIds, $dbIds));
                    
                    // Interseção
                    if ($finalFileIds === null) {
                        $finalFileIds = $tokenIds;
                    } else {
                        $finalFileIds = array_intersect($finalFileIds, $tokenIds);
                    }
                    $this->log("DEBUG: Final IDs after token '$token': " . count($finalFileIds));
                    
                    if (empty($finalFileIds)) {
                        $this->log("DEBUG: No IDs remaining after token '$token'. Breaking.");
                        break;
                    }
                } // End foreach tokens
            }
            $this->log("DEBUG: Token loop finished. Final IDs count: " . ($finalFileIds === null ? 'NULL' : count($finalFileIds)));

            // Filtro por Tags Explícitas (#)
            $explicitTagIds = null;
            $this->log("DEBUG: Checking explicit tags: " . json_encode($tags));
            if (!empty($tags)) {
                $explicitTagIds = $this->getFileIdsByTags($tags, $tagOperator);
                $this->log("DEBUG: Explicit tag IDs count: " . count($explicitTagIds));
                
                // CRITICAL FIX: Do NOT return empty immediately if tags are not found.
                // If operator is OR, we still want the Universal Search results.
                // If operator is AND, the intersection later will handle it (intersect with empty = empty).
                /*
                if (empty($explicitTagIds)) {
                    $this->log("DEBUG: Explicit tags returned empty. Returning [].");
                    return [];
                }
                */
            }

            // Combinar Universal com Explícito
            $candidatesIds = null;
            if ($finalFileIds !== null && $explicitTagIds !== null) {
                if ($tagOperator === 'OR') {
                    // União: (Universal) OR (Tags)
                    $candidatesIds = array_unique(array_merge($finalFileIds, $explicitTagIds));
                    $this->log("DEBUG: Logic OR. Univ: " . count($finalFileIds) . " Tags: " . count($explicitTagIds) . " Merged: " . count($candidatesIds));
                } else {
                    // Interseção: (Universal) AND (Tags)
                    $candidatesIds = array_intersect($finalFileIds, $explicitTagIds);
                }
            } elseif ($finalFileIds !== null) {
                $candidatesIds = $finalFileIds;
                $this->log("DEBUG: Only Universal results: " . count($candidatesIds));
            } elseif ($explicitTagIds !== null) {
                $candidatesIds = $explicitTagIds;
                $this->log("DEBUG: Only Tag results: " . count($candidatesIds));
            }
            $this->log("DEBUG: Candidates determined. Count: " . ($candidatesIds === null ? 'NULL' : count($candidatesIds)));

            // Se candidatesIds for vazio array
            if ($candidatesIds !== null && empty($candidatesIds)) {
                $this->log("DEBUG: Candidates empty after combination.");
                return [];
            }
            
            // Se candidatesIds for null (sem busca), buscar recentes ou por tipo
            // Mas como é FullText, geralmente esperamos uma busca.
            // Se for null, vamos buscar tudo do ES (wildcard *) se tiver fileType, ou recentes.
            
            $results = [];
            $candidates = [];

            if ($candidatesIds !== null) {
                // Paginação manual nos IDs
                // Precisamos carregar os arquivos para verificar permissão/existência
                // Isso pode ser lento se forem muitos IDs.
                // Vamos aplicar offset/limit aqui se possível?
                // Não, porque alguns IDs podem não ser arquivos válidos ou visíveis.
                
                // Vamos iterar e carregar até preencher o limit
                $count = 0;
                $skipped = 0;
                
                foreach ($candidatesIds as $fileId) {
                    try {
                        $nodes = $userFolder->getById($fileId);
                        if (empty($nodes)) {
                            // $this->log("DEBUG: File $fileId not found or not visible.");
                            continue;
                        }
                        $file = $nodes[0];
                        
                        if ($file->getType() !== FileInfo::TYPE_FILE) {
                            // $this->log("DEBUG: File $fileId is not a file.");
                            continue;
                        }
                        if (strpos($file->getName(), '.') === 0) continue;
                        
                        // Excluir arquivos de sistema (.pek, .cfa)
                        $ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
                        if (in_array($ext, ['pek', 'cfa'])) continue;

                        if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) {
                            $this->log("DEBUG: File $fileId (" . $file->getName() . ") type mismatch.");
                            continue;
                        }
                        
                        // Filtrar por extensões excluídas
                        if (!empty($excludedExtensions)) {
                            // $ext já foi calculado acima (sem o ponto), adicionar ponto para verificar na lista que tem pontos
                            // A lista vem do JS como ['.pdf', '.xls'], e o pathinfo retorna 'pdf'
                            if (in_array('.' . $ext, $excludedExtensions)) {
                                $this->log("DEBUG: File $fileId (" . $file->getName() . ") excluded extension: .$ext");
                                continue;
                            }
                        }
                        
                        if ($skipped < $offset) {
                            $skipped++;
                            continue;
                        }
                        
                        $candidates[] = $file;
                        $count++;
                        
                        if ($count >= $limit) break;
                        
                    } catch (\Exception $e) {
                         $this->log("DEBUG: Exception for file $fileId: " . $e->getMessage());
                         continue; 
                    }
                }
            } else {
                // Fallback se não tem termo nem tag: buscar recentes ou por tipo
                // (Reutilizando lógica do searchFiles)
                return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset, $excludedExtensions);
            }

            // Carregar tags
            $tagsByFileId = [];
            if (!empty($candidates)) {
                $fileIds = array_map(function($f) { return $f->getId(); }, $candidates);
                $tagsByFileId = $this->getTagsForFiles($fileIds);
            }
            
            foreach ($candidates as $file) {
                $fileTags = isset($tagsByFileId[$file->getId()]) ? $tagsByFileId[$file->getId()] : [];
                $result = $this->formatFileResult($file, $fileTags);
                $result['searchType'] = 'hybrid';
                $results[] = $result;
            }
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->lastError = "EXCEPTION in searchFilesWithFullText: " . $e->getMessage();
            $this->log($this->lastError);
            // Fallback seguro
            try {
                return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset, $excludedExtensions);
            } catch (\Throwable $e2) {
                $this->log("Fallback failed: " . $e2->getMessage());
                return [];
            }
        }
    }

    private function getIdsFromElasticsearch($token) {
        $url = 'http://187.45.162.16:9200/cob2023/_search';
        
        // Buscar apenas em Title e Content
        // Dividir termos por espaço para permitir buscas compostas (ex: "BAS SIDNEY")
        $terms = array_filter(explode(' ', $token), function($t) { return !empty(trim($t)); });
        
        if (empty($terms)) return [];

        $mustClauses = [];
        foreach ($terms as $term) {
            $wildcard = '*' . $term . '*';
            $mustClauses[] = [
                'bool' => [
                    'should' => [
                        ['wildcard' => ['title' => ['value' => $wildcard, 'case_insensitive' => true]]],
                        ['wildcard' => ['content' => ['value' => $wildcard, 'case_insensitive' => true]]],
                        ['fuzzy' => ['title' => ['value' => $term, 'fuzziness' => 'AUTO']]],
                        ['fuzzy' => ['content' => ['value' => $term, 'fuzziness' => 'AUTO']]]
                    ],
                    'minimum_should_match' => 1
                ]
            ];
        }

        $query = [
            'size' => 10000,
            '_source' => false,
            'query' => [
                'bool' => [
                    'must' => $mustClauses
                ]
            ]
        ];

        $payload = json_encode($query);
        $this->log("DEBUG: ES Query for '$token': " . $payload);

        $payload = json_encode($query);
        
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) return [];
            
            $data = json_decode($response, true);
            if (!isset($data['hits']['hits'])) return [];
            
            $ids = [];
            foreach ($data['hits']['hits'] as $hit) {
                $elasticId = $hit['_id'];
                if (strpos($elasticId, 'files:') === 0) {
                    $ids[] = (int) substr($elasticId, 6);
                } else {
                    $ids[] = (int) $elasticId;
                }
            }
            return $ids;
            
        } catch (\Exception $e) {
            return [];
        }
    }

    // Mantendo searchDirectElasticsearch para compatibilidade ou debug se necessário
    private function searchDirectElasticsearch($term, $tags = [], $limit = 100, $offset = 0)
    {
        return []; 
    }

    public function debugFullTextSearch()
    {
        $debug = [];
        $debug['class_exists'] = class_exists('\OCP\FullTextSearch\IFullTextSearchManager');
        $debug['manager_exists'] = $this->fullTextSearchManager !== null;
        $debug['last_error'] = $this->lastError;
        $debug['curl_exists'] = function_exists('curl_init');
        
        if ($this->fullTextSearchManager) {
            try {
                $debug['is_available'] = $this->fullTextSearchManager->isAvailable();
            } catch (\Throwable $e) {
                $debug['is_available_error'] = $e->getMessage();
            }
        }
        return $debug;
    }

    public function isFullTextSearchAvailable()
    {
        if (!$this->fullTextSearchManager) {
            return false;
        }
        try {
            return $this->fullTextSearchManager->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getFileIdsByTags($tags, $operator)
    {
        $fileIds = [];
        try {
            $tagIds = [];
            foreach ($tags as $tagName) {
                $tagId = $this->getTagIdByName($tagName);
                if ($tagId) {
                    $tagIds[] = $tagId;
                } else if ($operator === 'AND') {
                    return [];
                }
            }

            if (empty($tagIds)) {
                return [];
            }

            if ($operator === 'AND') {
                $fileIds = $this->systemTagObjectMapper->getObjectIdsForTags($tagIds, 'files');
            } else {
                $allFileIds = [];
                foreach ($tagIds as $tagId) {
                    $tagFileIds = $this->systemTagObjectMapper->getObjectIdsForTags([$tagId], 'files');
                    $allFileIds = array_merge($allFileIds, $tagFileIds);
                }
                $fileIds = array_unique($allFileIds);
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $fileIds;
    }

    private function getTagIdByName($tagName)
    {
        try {
            $allTags = $this->systemTagManager->getAllTags();
            $bestMatchId = null;
            $shortestDistance = 999;
            
            foreach ($allTags as $tag) {
                $checkName = $tag->getName();
                
                // 1. Exact Match
                if ($checkName === $tagName) {
                    return $tag->getId();
                }
                
                // 2. Fuzzy Check
                // Só aplicar se não achou exato ainda
                if (strlen($tagName) > 3) {
                    $distance = levenshtein(strtoupper($checkName), strtoupper($tagName));
                    $limit = strlen($tagName) > 5 ? 2 : 1;
                    
                    if ($distance <= $limit && $distance < $shortestDistance) {
                        $shortestDistance = $distance;
                        $bestMatchId = $tag->getId();
                    }
                }
            }
            
            if ($bestMatchId !== null) {
                $this->log("DEBUG: getTagIdByName Fuzzy Match: '$tagName' -> ID $bestMatchId (Dist: $shortestDistance)");
                return $bestMatchId;
            }

        } catch (\Throwable $e) {
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

    private function tagsMatch($fileTags, $requiredTags, $tagOperator)
    {
        $fileTagNames = array_column($fileTags, 'name');
        $matches = array_intersect($requiredTags, $fileTagNames);

        if ($tagOperator === 'AND') {
            return count($matches) === count($requiredTags);
        } else {
            return count($matches) > 0;
        }
    }

    private function formatFileResult($file, $tags = null)
    {
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

    private function getTagsForFiles($fileIds)
    {
        if (empty($fileIds)) {
            return [];
        }

        try {
            $tagsByObjectId = $this->systemTagObjectMapper->getTagIdsForObjects($fileIds, 'files');
            
            $allTagIds = [];
            foreach ($tagsByObjectId as $objectId => $tagIds) {
                foreach ($tagIds as $tagId) {
                    $allTagIds[$tagId] = $tagId;
                }
            }

            if (empty($allTagIds)) {
                return [];
            }

            $tagsInfo = $this->systemTagManager->getTagsByIds(array_values($allTagIds));
            $tagsMap = [];
            foreach ($tagsInfo as $tag) {
                $tagsMap[$tag->getId()] = [
                    'id' => $tag->getId(),
                    'name' => $tag->getName(),
                    'color' => $tag->isUserAssignable() ? 'blue' : 'red'
                ];
            }

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

        } catch (\Throwable $e) {
            error_log("[AdvancedSearch] Error in getTagsForFiles: " . $e->getMessage());
            return [];
        }
    }

    private function getFileTags($fileId)
    {
        $tags = $this->getTagsForFiles([$fileId]);
        return isset($tags[$fileId]) ? $tags[$fileId] : [];
    }

    private function searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator)
    {
        if (!empty($tags) && empty($fileType)) {
            return $this->searchByTagsOnly($userFolder, $tags, $tagOperator);
        }
        if (!empty($fileType) && empty($tags)) {
            return $this->searchByFileTypeOnly($userFolder, $fileType);
        }
        try {
            return $userFolder->getRecent(1000);
        } catch (\Throwable $e) {
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
            } catch (\Throwable $e) {
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
                $searchResults = $userFolder->search('.' . $extension);
                foreach ($searchResults as $result) {
                    if (strtolower(pathinfo($result->getName(), PATHINFO_EXTENSION)) === $extension) {
                        $files[] = $result;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $files;
    }
}
