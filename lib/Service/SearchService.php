<?php
namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;

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

    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '') {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $results = [];

        // Busca por nome de arquivo
        if (!empty($filename)) {
            $files = $userFolder->search($filename);
        } else {
            $files = $this->getAllFiles($userFolder);
        }

        // Filtrar por tipo de arquivo
        if (!empty($fileType)) {
            $files = $this->filterByFileType($files, $fileType);
        }

        // Filtrar por tags se especificadas
        if (!empty($tags)) {
            $files = $this->filterByTags($files, $tags, $tagOperator);
        }

        // Formatar resultados
        foreach ($files as $file) {
            $results[] = [
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

        return $results;
    }

    private function getAllFiles($folder) {
        $files = [];
        try {
            foreach ($folder->getDirectoryListing() as $node) {
                if ($node->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                    $files[] = $node;
                } elseif ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                    $files = array_merge($files, $this->getAllFiles($node));
                }
            }
        } catch (\Exception $e) {
            // Ignorar pastas sem permissão
        }
        return $files;
    }

    private function filterByFileType($files, $fileType) {
        $filteredFiles = [];
        
        foreach ($files as $file) {
            $mimetype = $file->getMimetype();
            $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
            
            $matches = false;
            
            switch ($fileType) {
                case 'image':
                    $matches = strpos($mimetype, 'image/') === 0;
                    break;
                case 'document':
                    $matches = in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'txt']) || 
                              strpos($mimetype, 'text/') === 0 ||
                              strpos($mimetype, 'application/msword') === 0 ||
                              strpos($mimetype, 'application/vnd.openxmlformats-officedocument.wordprocessingml') === 0;
                    break;
                case 'video':
                    $matches = strpos($mimetype, 'video/') === 0;
                    break;
                case 'audio':
                    $matches = strpos($mimetype, 'audio/') === 0;
                    break;
                case 'pdf':
                    $matches = $mimetype === 'application/pdf';
                    break;
            }
            
            if ($matches) {
                $filteredFiles[] = $file;
            }
        }
        
        return $filteredFiles;
    }

    private function filterByTags($files, $tags, $operator) {
        $filteredFiles = [];
        
        foreach ($files as $file) {
            $fileTags = $this->getFileTags($file->getId());
            $fileTagNames = array_column($fileTags, 'name');
            
            $matches = array_intersect($tags, $fileTagNames);
            
            if ($operator === 'AND') {
                // Todas as tags devem estar presentes
                if (count($matches) === count($tags)) {
                    $filteredFiles[] = $file;
                }
            } else { // OR
                // Pelo menos uma tag deve estar presente
                if (count($matches) > 0) {
                    $filteredFiles[] = $file;
                }
            }
        }
        
        return $filteredFiles;
    }

    private function getFileTags($fileId) {
        try {
            $tags = $this->systemTagObjectMapper->getTagsForObject($fileId, 'files');
            $result = [];
            
            if (empty($tags)) {
                return $result;
            }
            
            $tagData = $this->systemTagManager->getTagsById($tags);
            foreach ($tagData as $tagInfo) {
                $result[] = [
                    'id' => $tagInfo->getId(),
                    'name' => $tagInfo->getName(),
                    'color' => $tagInfo->isUserAssignable() ? 'blue' : 'red'
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
}