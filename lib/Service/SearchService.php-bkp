<?php
namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;
use OCP\Files\FileInfo;
// ADICIONAR ESTAS IMPORTS
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\ISearchRequest;

class SearchService {
    private $rootFolder;
    private $userSession;
    private $systemTagManager;
    private $systemTagObjectMapper;
    // ADICIONAR ESTA PROPRIEDADE
    private $fullTextSearchManager;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagObjectMapper,
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->systemTagManager = $systemTagManager;
        $this->systemTagObjectMapper = $systemTagObjectMapper;
         // ADICIONAR ESTA LINHA
         // Tentar injetar o FullTextSearchManager de forma segura
        try {
            $this->fullTextSearchManager = \OC::$server->get(\OCP\FullTextSearch\IFullTextSearchManager::class);
        } catch (\Exception $e) {
            $this->fullTextSearchManager = null;
            error_log('FullTextSearchManager não disponível: ' . $e->getMessage());
        }
    }

    // ADICIONAR ESTE MÉTODO NOVO ANTES DO searchFiles EXISTENTE
    public function searchFilesWithFullText($query = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        // Verificar se full text search está disponível
        if ($this->fullTextSearchManager && $this->fullTextSearchManager->isAvailable()) {
            try {
                return $this->performFullTextSearch($query, $tags, $tagOperator, $fileType, $limit, $offset);
            } catch (\Exception $e) {
                // Se falhar, usar busca tradicional como fallback
                error_log('Full text search failed, falling back to traditional search: ' . $e->getMessage());
                return $this->searchFiles($query, $tags, $tagOperator, $fileType, $limit, $offset);
            }
        } else {
            // Usar busca tradicional se full text search não estiver disponível
            return $this->searchFiles($query, $tags, $tagOperator, $fileType, $limit, $offset);
        }
    }

    // ADICIONAR ESTE MÉTODO NOVO
    private function performFullTextSearch($query, $tags, $tagOperator, $fileType, $limit, $offset) {
        $user = $this->userSession->getUser();
        
        // Criar requisição de busca
        $searchRequest = $this->fullTextSearchManager->createSearchRequest();
        
        // Configurar busca
        if (!empty($query)) {
            $searchRequest->setSearch($query);
        }
        
        // Configurar paginação
        $page = floor($offset / $limit) + 1;
        $searchRequest->setPage($page);
        $searchRequest->setSize($limit);
        
        // Definir provedor (arquivos)
        $searchRequest->setProviders(['files']);
        
        // Configurar opções específicas
        $searchRequest->setOptions([
            'files_local' => true,
            'files_external' => true,
            'files_group_folders' => true,
        ]);
        
        // Aplicar filtros por tipo de arquivo
        if (!empty($fileType)) {
            $this->applyFileTypeFilter($searchRequest, $fileType);
        }
        
        // Executar busca
        $searchResult = $this->fullTextSearchManager->search($user->getUID(), $searchRequest);
        
        // Processar resultados
        $results = [];
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        
        foreach ($searchResult->getDocuments() as $document) {
            try {
                // Obter informações do arquivo a partir do documento
                $fileInfo = $this->getFileInfoFromDocument($document, $userFolder);
                if ($fileInfo) {
                    // Aplicar filtros de tags se necessário
                    if (!empty($tags) && !$this->fileMatchesTags($fileInfo->getId(), $tags, $tagOperator)) {
                        continue;
                    }
                    
                    $results[] = $this->formatFileResult($fileInfo, $document);
                }
            } catch (\Exception $e) {
                // Skip arquivos que não podem ser acessados
                continue;
            }
        }
        
        return $results;
    }

    // ADICIONAR ESTE MÉTODO NOVO
    private function applyFileTypeFilter($searchRequest, $fileType) {
        $mimeTypes = $this->getMimeTypesForFileType($fileType);
        if (!empty($mimeTypes)) {
            $searchRequest->addFilter('mimetype', $mimeTypes);
        }
    }

    // ADICIONAR ESTE MÉTODO NOVO
    private function getMimeTypesForFileType($fileType) {
        switch ($fileType) {
            case 'image':
                return ['image/*'];
            case 'document':
                return [
                    'text/*',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.oasis.opendocument.text'
                ];
            case 'video':
                return ['video/*'];
            case 'audio':
                return ['audio/*'];
            case 'pdf':
                return ['application/pdf'];
            default:
                return [];
        }
    }

    // ADICIONAR ESTE MÉTODO NOVO
    private function getFileInfoFromDocument($document, $userFolder) {
        // Tentar obter o arquivo pelo ID
        $fileId = $document->getId();
        
        try {
            $nodes = $userFolder->getById($fileId);
            if (!empty($nodes)) {
                return $nodes[0];
            }
        } catch (\Exception $e) {
            // Se não conseguir pelo ID, tentar pelo path
            $path = $document->getInfoArray()['path'] ?? '';
            if ($path) {
                try {
                    return $userFolder->get($path);
                } catch (\Exception $e) {
                    return null;
                }
            }
        }
        
        return null;
    }

    

    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
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
                $searchResults = $userFolder->search($searchTerm);
            } else {
                $searchResults = [];
            }
        } else {
            // Se não tem nome, buscar por outros critérios
            $searchResults = $this->searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator);
        }
        
        // Filtrar e processar resultados
        $filteredResults = [];
        foreach ($searchResults as $file) {
            // Verificar se é arquivo (não pasta)
            if ($file->getType() !== FileInfo::TYPE_FILE) {
                continue;
            }
            
            // Filtrar por tipo de arquivo
            if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) {
                continue;
            }
            
            // Filtrar por tags
            if (!empty($tags) && !$this->fileMatchesTags($file->getId(), $tags, $tagOperator)) {
                continue;
            }
            
            $filteredResults[] = $file;
        }
        
        // Aplicar paginação
        $paginatedResults = array_slice($filteredResults, $offset, $limit);
        
        // Formatar resultados
        foreach ($paginatedResults as $file) {
            $results[] = $this->formatFileResult($file);
        }

        return $results;
    }

    private function searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator) {
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

    private function searchByTagsOnly($userFolder, $tags, $tagOperator) {
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

    private function searchByFileTypeOnly($userFolder, $fileType) {
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
    private function getFileIdsByTags($tags, $operator) {
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

    private function getTagIdByName($tagName) {
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

    private function matchesFileType($file, $fileType) {
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

    private function getExtensionsForFileType($fileType) {
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

    private function fileMatchesTags($fileId, $tags, $tagOperator) {
        $fileTags = $this->getFileTags($fileId);
        $fileTagNames = array_column($fileTags, 'name');
        
        $matches = array_intersect($tags, $fileTagNames);
        
        if ($tagOperator === 'AND') {
            return count($matches) === count($tags);
        } else { // OR
            return count($matches) > 0;
        }
    }

    private function formatFileResult($file, $document = null) {
        $result = [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'path' => $file->getPath(),
            'type' => $file->getType(),
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
            'mimetype' => $file->getMimetype(),
            'tags' => $this->getFileTags($file->getId())
        ];
        
        // Se temos informações do full text search, adicionar
        if ($document) {
            $result['score'] = $document->getScore();
            $result['highlights'] = $document->getHighlights();
            $result['excerpt'] = $document->getExcerpt();
        }
        
        return $result;
    }

    // MÉTODO TAMBÉM CORRIGIDO PARA USAR getTagIdsForObjects
    private function getFileTags($fileId) {
        try {
            $tagIds = $this->systemTagObjectMapper->getTagIdsForObjects([$fileId], 'files');
            
            if (empty($tagIds) || !isset($tagIds[$fileId])) {
                return [];
            }
            
            $result = [];
            $tags = $this->systemTagManager->getTagsByIds($tagIds[$fileId]);
            
            foreach ($tags as $tag) {
                $result[] = [
                    'id' => $tag->getId(),
                    'name' => $tag->getName(),
                    'color' => $tag->isUserAssignable() ? 'blue' : 'red'
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
}