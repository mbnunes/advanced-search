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

    // SUA FUNÇÃO ORIGINAL searchFiles - EXATAMENTE COMO ESTAVA
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

    // NOVA FUNÇÃO SIMPLES PARA BUSCA MELHORADA (SEM QUEBRAR O QUE JÁ FUNCIONA)
    public function searchFilesEnhanced($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        // Por enquanto, apenas chamar a função original
        // Mais tarde podemos melhorar isso se conseguirmos acessar o FullTextSearch
        $results = $this->searchFiles($filename, $tags, $tagOperator, $fileType, $limit, $offset);
        
        // Marcar como enhanced nos resultados
        foreach ($results as &$result) {
            $result['searchType'] = 'enhanced';
        }
        
        return $results;
    }

    // FUNÇÃO SIMPLES PARA VERIFICAR SE FULLTEXTSEARCH ESTÁ DISPONÍVEL
    public function isFullTextSearchAvailable() {
        try {
            // Verificar se o app está habilitado usando OCC
            $output = [];
            $return_var = 0;
            exec('sudo -u www-data php ' . \OC::$SERVERROOT . '/occ app:list | grep fulltextsearch', $output, $return_var);
            
            // Se encontrou fulltextsearch habilitado
            foreach ($output as $line) {
                if (strpos($line, 'fulltextsearch') !== false && strpos($line, 'Enabled') !== false) {
                    return true;
                }
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    // TODAS AS SUAS FUNÇÕES ORIGINAIS ABAIXO - SEM ALTERAÇÃO

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