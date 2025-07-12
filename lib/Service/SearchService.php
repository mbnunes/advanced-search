<?php
namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;
use OCP\Files\FileInfo;

class SearchService {
    private $rootFolder;
    private $userSession;
    private $systemTagManager;
    private $systemTagObjectMapper;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagObjectMapper
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->systemTagManager = $systemTagManager;
        $this->systemTagObjectMapper = $systemTagObjectMapper;
    }

        // MANTER SUA FUNÇÃO ORIGINAL searchFiles
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

        // NOVA FUNÇÃO PARA FULL TEXT SEARCH
    public function searchFilesWithFullText($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        // Se não há filename, usar busca tradicional
        if (empty($filename)) {
            return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        }

        try {
            return $this->performFullTextSearch($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        } catch (\Exception $e) {
            error_log('FullTextSearch failed, falling back to traditional: ' . $e->getMessage());
            return $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        }
    }

        private function performFullTextSearch($filename, $tags, $tagOperator, $fileType, $limit, $offset) {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        // Encontrar o caminho do Nextcloud
        $nextcloudPath = $this->findNextcloudPath();
        
        // Escapar parâmetros para segurança
        $escapedQuery = escapeshellarg($filename);
        $escapedUser = escapeshellarg($user->getUID());
        
        // Comando para busca via FullTextSearch
        $command = "sudo -u www-data php {$nextcloudPath}/occ fulltextsearch:search --user={$escapedUser} --provider=files {$escapedQuery} 2>&1";
        
        error_log("Executing FullTextSearch command: $command");
        
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        
        $outputString = implode("\n", $output);
        error_log("FullTextSearch output: " . $outputString);
        
        if ($return_var !== 0) {
            throw new \Exception("FullTextSearch command failed with return code: $return_var");
        }
        
        return $this->parseFullTextResults($outputString, $tags, $tagOperator, $fileType, $limit, $offset);
    }

        private function parseFullTextResults($output, $tags, $tagOperator, $fileType, $limit, $offset) {
        $results = [];
        $userFolder = $this->rootFolder->getUserFolder($this->userSession->getUser()->getUID());
        
        // Tentar parsear JSON se disponível
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Procurar por diferentes formatos de saída
            $fileId = null;
            
            // Formato 1: JSON
            if (strpos($line, '{') === 0) {
                $data = json_decode($line, true);
                if (isset($data['id'])) {
                    $fileId = (int)$data['id'];
                }
            }
            
            // Formato 2: Procurar por ID de arquivo na linha
            if (!$fileId && preg_match('/(?:id|fileid)[:\s]+(\d+)/', $line, $matches)) {
                $fileId = (int)$matches[1];
            }
            
            // Formato 3: Procurar por paths que contenham números
            if (!$fileId && preg_match('/\/(\d+)\//', $line, $matches)) {
                $fileId = (int)$matches[1];
            }
            
            if ($fileId) {
                try {
                    $nodes = $userFolder->getById($fileId);
                    if (!empty($nodes)) {
                        $file = $nodes[0];
                        
                        if ($file->getType() === FileInfo::TYPE_FILE) {
                            // Aplicar filtros
                            if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) {
                                continue;
                            }
                            
                            if (!empty($tags) && !$this->fileMatchesTags($file->getId(), $tags, $tagOperator)) {
                                continue;
                            }
                            
                            $result = $this->formatFileResult($file);
                            $result['searchType'] = 'fulltext';
                            
                            // Evitar duplicatas
                            $exists = false;
                            foreach ($results as $existing) {
                                if ($existing['id'] === $result['id']) {
                                    $exists = true;
                                    break;
                                }
                            }
                            
                            if (!$exists) {
                                $results[] = $result;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log('Error processing file ID ' . $fileId . ': ' . $e->getMessage());
                    continue;
                }
            }
        }
        
        // Se não encontrou resultados via parsing, tentar abordagem alternativa
        if (empty($results)) {
            error_log('No results found via parsing, trying alternative approach');
            return $this->alternativeFullTextSearch($output, $tags, $tagOperator, $fileType, $limit, $offset);
        }
        
        // Aplicar paginação
        return array_slice($results, $offset, $limit);
    }

        private function alternativeFullTextSearch($output, $tags, $tagOperator, $fileType, $limit, $offset) {
        // Se o parsing falhou, fazer uma busca combinada
        $user = $this->userSession->getUser();
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        
        // Buscar arquivos que contenham o termo no nome (busca tradicional melhorada)
        $searchTerm = $this->extractSearchTermFromOutput($output);
        if (!$searchTerm) {
            throw new \Exception('Could not extract search term from FullTextSearch output');
        }
        
        $searchResults = $userFolder->search($searchTerm);
        
        // Expandir busca para variações do termo
        $searchVariations = $this->generateSearchVariations($searchTerm);
        foreach ($searchVariations as $variation) {
            try {
                $variationResults = $userFolder->search($variation);
                $searchResults = array_merge($searchResults, $variationResults);
            } catch (\Exception $e) {
                continue;
            }
        }
        
        // Processar resultados
        $filteredResults = [];
        foreach ($searchResults as $file) {
            if ($file->getType() !== FileInfo::TYPE_FILE) {
                continue;
            }
            
            if (!empty($fileType) && !$this->matchesFileType($file, $fileType)) {
                continue;
            }
            
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
            $result['searchType'] = 'enhanced';
            $results[] = $result;
        }
        
        return $results;
    }

        private function extractSearchTermFromOutput($output) {
        // Tentar extrair o termo de busca da saída
        if (preg_match('/search[:\s]+["\']?([^"\']+)["\']?/i', $output, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function generateSearchVariations($term) {
        $variations = [];
        
        // Adicionar partes do termo
        $words = explode(' ', $term);
        foreach ($words as $word) {
            if (strlen($word) > 2) {
                $variations[] = $word;
            }
        }
        
        // Adicionar variações com wildcards
        $variations[] = '*' . $term . '*';
        
        return array_unique($variations);
    }

    private function findNextcloudPath() {
        // Tentar encontrar o caminho do Nextcloud
        $possiblePaths = [
            '/var/www/nextcloud',
            '/var/www/html/nextcloud',
            '/var/www/html',
            \OC::$SERVERROOT
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path . '/occ')) {
                return $path;
            }
        }
        
        return \OC::$SERVERROOT;
    }

    public function isFullTextSearchAvailable() {
        try {
            $nextcloudPath = $this->findNextcloudPath();
            $output = [];
            $return_var = 0;
            exec("sudo -u www-data php {$nextcloudPath}/occ fulltextsearch:test --quiet 2>&1", $output, $return_var);
            
            return $return_var === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

        // MANTER TODAS AS SUAS FUNÇÕES ORIGINAIS ABAIXO (sem alteração)

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
            'tags' => $this->getFileTags($file->getId())
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