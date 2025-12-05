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

    // MANTER SUA FUNÇÃO ORIGINAL searchFiles COM LÓGICA UNIVERSAL
    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0)
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
            
            // Busca case-insensitive parcial nas tags
            foreach ($allTags as $tag) {
                if (stripos($tag->getName(), $token) !== false) {
                    $matchingTagIds[] = $tag->getId();
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

    public function searchFilesWithFullText($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
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
                    // a. ES (Title/Content)
                    $esIds = $this->getIdsFromElasticsearch($token);
                    
                    // b. MySQL (Tags)
                    $dbIds = $this->getFileIdsByTagToken($token);
                    
                    // c. União
                    $tokenIds = array_unique(array_merge($esIds, $dbIds));
                    
                    // Interseção
                    if ($finalFileIds === null) {
                        $finalFileIds = $tokenIds;
                    } else {
                        $finalFileIds = array_intersect($finalFileIds, $tokenIds);
                    }
                    
                    if (empty($finalFileIds)) {
                        break;
                    }
                }
            }

            // Se não houve busca por nome/universal, $finalFileIds é null.
            
            // Filtro por Tags Explícitas (#)
            $explicitTagIds = null;
            if (!empty($tags)) {
                $explicitTagIds = $this->getFileIdsByTags($tags, $tagOperator);
                if (empty($explicitTagIds)) {
                    return [];
                }
            }

            // Combinar Universal com Explícito
            $candidatesIds = null;
            if ($finalFileIds !== null && $explicitTagIds !== null) {
                $candidatesIds = array_intersect($finalFileIds, $explicitTagIds);
            } elseif ($finalFileIds !== null) {
                $candidatesIds = $finalFileIds;
            } elseif ($explicitTagIds !== null) {
                $candidatesIds = $explicitTagIds;
            }

            // Se candidatesIds for vazio array
            if ($candidatesIds !== null && empty($candidatesIds)) {
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
                        if (empty($nodes)) continue;
                        $file = $nodes[0];
                        
                        if ($file->getType() !== FileInfo::TYPE_FILE) continue;
                        if (strpos($file->getName(), '.') === 0) continue;
                        if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) continue;
                        
                        if ($skipped < $offset) {
                            $skipped++;
                            continue;
                        }
                        
                        $candidates[] = $file;
                        $count++;
                        
                        if ($count >= $limit) break;
                        
                    } catch (\Exception $e) { continue; }
                }
            } else {
                // Fallback se não tem termo nem tag: buscar recentes ou por tipo
                // (Reutilizando lógica do searchFiles)
                return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
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
                return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
            } catch (\Throwable $e2) {
                $this->log("Fallback failed: " . $e2->getMessage());
                return [];
            }
        }
    }

    private function getIdsFromElasticsearch($token) {
        $url = 'http://187.45.162.16:9200/cob2023/_search';
        
        // Buscar apenas em Title e Content
        $wildcard = '*' . $token . '*';
        $query = [
            'size' => 10000, // Limite alto para IDs
            '_source' => false, // Só queremos IDs
            'query' => [
                'bool' => [
                    'should' => [
                        ['wildcard' => ['title' => ['value' => $wildcard, 'case_insensitive' => true]]],
                        ['wildcard' => ['content' => ['value' => $wildcard, 'case_insensitive' => true]]]
                    ],
                    'minimum_should_match' => 1
                ]
            ]
        ];

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

    // Mantendo searchDirectElasticsearch para compatibilidade ou debug se necessário, 
    // mas agora usamos getIdsFromElasticsearch
    private function searchDirectElasticsearch($term, $tags = [], $limit = 100, $offset = 0)
    {
        // ... (código antigo mantido ou removido, mas como substituí o bloco todo, ele foi removido)
        return []; 
    }
}
