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

    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND') {
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
            $files = $userFolder->getDirectoryListing();
            $files = $this->getAllFiles($userFolder);
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
                'tags' => $this->getFileTags($file->getId())
            ];
        }

        return $results;
    }

    private function getAllFiles($folder) {
        $files = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                $files[] = $node;
            } elseif ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $files = array_merge($files, $this->getAllFiles($node));
            }
        }
        return $files;
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
            
            foreach ($tags as $tag) {
                $tagData = $this->systemTagManager->getTagsById([$tag]);
                foreach ($tagData as $tagInfo) {
                    $result[] = [
                        'id' => $tagInfo->getId(),
                        'name' => $tagInfo->getName(),
                        'color' => $tagInfo->isUserAssignable() ? 'blue' : 'red'
                    ];
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
}
