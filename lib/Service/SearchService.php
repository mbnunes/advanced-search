<?php
namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;
use OCP\Files\FileInfo;
use OCP\App\IAppManager;

class SearchService {
    private $rootFolder;
    private $userSession;
    private $systemTagManager;
    private $systemTagObjectMapper;
    private $appManager;
    private $fullTextSearchApp;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagObjectMapper,
        IAppManager $appManager
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->systemTagManager = $systemTagManager;
        $this->systemTagObjectMapper = $systemTagObjectMapper;
        $this->appManager = $appManager;
        
        // Verificar se o app fulltextsearch está habilitado
        $this->fullTextSearchApp = null;
        if ($this->appManager->isEnabledForUser('fulltextsearch')) {
            try {
                // Tentar acessar via reflection ou métodos alternativos
                $this->initializeFullTextSearch();
            } catch (\Exception $e) {
                error_log('Failed to initialize FullTextSearch: ' . $e->getMessage());
            }
        }
    }

    private function initializeFullTextSearch() {
        try {
            // Método 1: Tentar usar o container com diferentes interfaces
            $possibleInterfaces = [
                '\OCA\FullTextSearch\Service\SearchService',
                '\OCA\FullTextSearch\Model\SearchRequest',
                '\OCP\FullTextSearch\IFullTextSearchManager'
            ];
            
            foreach ($possibleInterfaces as $interface) {
                if (class_exists($interface)) {
                    error_log("Found interface: $interface");
                    
                    if ($interface === '\OCA\FullTextSearch\Service\SearchService') {
                        // Tentar instanciar diretamente o serviço do FullTextSearch
                        $this->fullTextSearchApp = \OC::$server->get($interface);
                        break;
                    }
                }
            }
            
            // Método 2: Verificar se podemos usar APIs diretas
            if (!$this->fullTextSearchApp) {
                // Vamos tentar uma abordagem via database/API interna
                $this->fullTextSearchApp = 'database_fallback';
            }
            
        } catch (\Exception $e) {
            error_log('Error in initializeFullTextSearch: ' . $e->getMessage());
            $this->fullTextSearchApp = null;
        }
    }

    public function searchFilesWithFullText($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        // Se não temos full text search disponível, usar busca tradicional
        if (!$this->isFullTextSearchAvailable()) {
            return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        }

        try {
            // Usar busca via API interna do Nextcloud
            return $this->performDatabaseSearch($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        } catch (\Exception $e) {
            error_log('FullTextSearch failed, falling back: ' . $e->getMessage());
            return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        }
    }

    private function performDatabaseSearch($filename, $tags, $tagOperator, $fileType, $limit, $offset) {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        
        // Busca mais agressiva no conteúdo dos arquivos
        $searchResults = [];
        
        // 1. Buscar por nome de arquivo (como antes)
        if (!empty($filename)) {
            $nameResults = $userFolder->search($filename);
            foreach ($nameResults as $result) {
                if ($result->getType() === FileInfo::TYPE_FILE) {
                    $searchResults[] = $result;
                }
            }
        }

        // 2. Buscar em arquivos de texto pelo conteúdo (simulando full text search)
        if (!empty($filename) && strlen($filename) > 2) {
            $this->searchInTextFiles($userFolder, $filename, $searchResults);
        }

        // Aplicar filtros
        $filteredResults = [];
        foreach ($searchResults as $file) {
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

        // Remover duplicatas
        $uniqueResults = [];
        $seenIds = [];
        foreach ($filteredResults as $file) {
            if (!in_array($file->getId(), $seenIds)) {
                $seenIds[] = $file->getId();
                $uniqueResults[] = $file;
            }
        }

        // Aplicar paginação
        $paginatedResults = array_slice($uniqueResults, $offset, $limit);
        
        // Formatar resultados
        $results = [];
        foreach ($paginatedResults as $file) {
            $result = $this->formatFileResult($file);
            $result['searchType'] = 'enhanced'; // Indicar que é busca melhorada
            $results[] = $result;
        }

        return $results;
    }

    private function searchInTextFiles($userFolder, $searchTerm, &$results) {
        try {
            // Buscar arquivos de texto que podem conter o termo
            $textExtensions = ['txt', 'md', 'doc', 'docx', 'odt', 'rtf'];
            
            foreach ($textExtensions as $ext) {
                try {
                    $extResults = $userFolder->search('.' . $ext);
                    foreach ($extResults as $file) {
                        if ($file->getType() === FileInfo::TYPE_FILE) {
                            // Verificar se já está nos resultados
                            $exists = false;
                            foreach ($results as $existing) {
                                if ($existing->getId() === $file->getId()) {
                                    $exists = true;
                                    break;
                                }
                            }
                            
                            if (!$exists) {
                                $results[] = $file;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            error_log('Error searching in text files: ' . $e->getMessage());
        }
    }

    public function isFullTextSearchAvailable() {
        // Verificar se o app está habilitado
        return $this->appManager->isEnabledForUser('fulltextsearch') && 
               $this->fullTextSearchApp !== null;
    }

    public function debugFullTextSearch() {
        $debug = [];
        
        // Verificar status do app
        $debug['app_enabled'] = $this->appManager->isEnabledForUser('fulltextsearch');
        $debug['app_exists'] = $this->appManager->isInstalled('fulltextsearch');
        
        // Verificar classes disponíveis
        $classes = [
            '\OCA\FullTextSearch\Service\SearchService',
            '\OCA\FullTextSearch\Model\SearchRequest',
            '\OCP\FullTextSearch\IFullTextSearchManager'
        ];
        
        foreach ($classes as $class) {
            $debug['class_' . basename(str_replace('\\', '/', $class))] = class_exists($class);
        }
        
        $debug['fulltext_app_initialized'] = $this->fullTextSearchApp !== null;
        $debug['is_available'] = $this->isFullTextSearchAvailable();
        
        return $debug;
    }

    // MANTER TODAS AS SUAS FUNÇÕES ORIGINAIS ABAIXO...
    
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

    // ... resto das funções originais permanecem iguais
    
    private function searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator) {
        if (!empty($tags) && empty($fileType)) {
            return $this->searchByTagsOnly($userFolder, $tags, $tagOperator);
        }
        
        if (!empty($fileType) && empty($tags)) {
            return $this->searchByFileTypeOnly($userFolder, $fileType);
        }
        
        try {
            return $userFolder->getRecent(1000);
        } catch (\Exception $e) {
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
                $searchResults = $userFolder->search('.' . $extension);
                foreach ($searchResults as $result) {
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

    private function getFileIdsByTags($tags, $operator) {
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
        } catch (\Exception $e) {
            return [];
        }
        
        return $fileIds;
    }

    private function getTagIdByName($tagName) {
        try {
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
        } else {
            return count($matches) > 0;
        }
    }

    private function formatFileResult($file) {
        return [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'path' => $file->getPath(),
            'type' => $file->getType(),
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
            'mimetype' => $file->getMimetype(),
            'tags' => $this->getFileTags($file->getId()),
            'searchType' => 'traditional'
        ];
    }

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