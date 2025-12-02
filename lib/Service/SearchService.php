<?php

namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;
use OCP\Files\FileInfo;
use OCP\App\IAppManager;
use OCP\FullTextSearch\Model\SearchRequest;
use OCP\FullTextSearch\IFullTextSearchManager;

class SearchService
{
    private $rootFolder;
    private $userSession;
    private $systemTagManager;
    private $systemTagObjectMapper;
    private $appManager;
    private $fullTextSearchManager;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagObjectMapper,
        IAppManager $appManager,
        ?IFullTextSearchManager $fullTextSearchManager = null
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->systemTagManager = $systemTagManager;
        $this->systemTagObjectMapper = $systemTagObjectMapper;
        $this->appManager = $appManager;
        $this->fullTextSearchManager = $fullTextSearchManager;
    }

    public function searchFiles(string $query = '', array $tags = [], string $tagOperator = 'AND', string $fileType = '', int $limit = 100, int $offset = 0): array
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $results = [];

        // Parse query for tags if tags array is empty (unified search fallback)
        $searchTerm = $query;
        if (empty($tags) && !empty($query)) {
            $parsed = $this->parseQuery($query);
            $searchTerm = $parsed['term'];
            $tags = $parsed['tags'];
            // Default to AND for inline tags
            $tagOperator = 'AND';
        }

        // Search files
        if (!empty($searchTerm)) {
            if (strlen($searchTerm) > 0) {
                $searchResults = $userFolder->search($searchTerm);
            } else {
                $searchResults = [];
            }
        } else {
            $searchResults = $this->searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator);
        }

        // Filter and process results
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

        // Pagination
        $paginatedResults = array_slice($filteredResults, $offset, $limit);

        // Format results
        foreach ($paginatedResults as $file) {
            $results[] = $this->formatFileResult($file);
        }

        return $results;
    }

    public function searchFilesWithFullText(string $query = '', array $tags = [], string $tagOperator = 'AND', string $fileType = '', int $limit = 100, int $offset = 0): array
    {
        if (!$this->fullTextSearchManager || empty($query)) {
            return $this->searchFiles($query, $tags, $tagOperator, $fileType, $limit, $offset);
        }

        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \Exception('User not logged in');
            }

            $searchRequest = new SearchRequest();
            $searchRequest->setSearch($query);
            $searchRequest->setAuthor($user->getUID());
            
            $page = floor($offset / $limit) + 1;
            $searchRequest->setPage($page);
            $searchRequest->setSize($limit);
            $searchRequest->setProviders(['files']);
            
            $searchResult = $this->fullTextSearchManager->search($user->getUID(), $searchRequest);
            
            $results = [];
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            
            foreach ($searchResult->getDocuments() as $document) {
                try {
                    $fileId = (int) $document->getId();
                    $nodes = $userFolder->getById($fileId);
                    
                    if (!empty($nodes) && $nodes[0]->getType() === FileInfo::TYPE_FILE) {
                        $fileInfo = $nodes[0];
                        
                        if (!empty($fileType) && !$this->matchesFileType($fileInfo, $fileType)) {
                            continue;
                        }
                        
                        // Note: FullTextSearch might handle tags internally if configured, 
                        // but here we are just searching by text. 
                        // If strict tag filtering is needed on top of FTS results, uncomment below:
                        /*
                        if (!empty($tags) && !$this->fileMatchesTags($fileInfo->getId(), $tags, $tagOperator)) {
                            continue;
                        }
                        */
                        
                        $result = $this->formatFileResult($fileInfo);
                        $result['searchType'] = 'fulltext';
                        $result['score'] = $document->getScore();
                        $result['excerpt'] = $document->getExcerpts();
                        $results[] = $result;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            
            return $results;
            
        } catch (\Throwable $e) {
            return $this->searchFiles($query, $tags, $tagOperator, $fileType, $limit, $offset);
        }
    }

    private function parseQuery(string $query): array
    {
        $tags = [];
        $terms = [];
        
        $parts = explode(' ', $query);
        foreach ($parts as $part) {
            if (strpos($part, '#') === 0 && strlen($part) > 1) {
                $tags[] = substr($part, 1);
            } else {
                $terms[] = $part;
            }
        }
        
        return [
            'term' => implode(' ', $terms),
            'tags' => $tags
        ];
    }

    public function isFullTextSearchAvailable(): bool
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

    private function searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator): array
    {
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

    private function searchByTagsOnly($userFolder, $tags, $tagOperator): array
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
                continue;
            }
        }

        return $files;
    }

    private function searchByFileTypeOnly($userFolder, $fileType): array
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
            } catch (\Exception $e) {
                continue;
            }
        }

        return $files;
    }

    private function getFileIdsByTags($tags, $operator): array
    {
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
                return $this->systemTagObjectMapper->getObjectIdsForTags($tagIds, 'files');
            } else {
                $allFileIds = [];
                foreach ($tagIds as $tagId) {
                    $tagFileIds = $this->systemTagObjectMapper->getObjectIdsForTags([$tagId], 'files');
                    $allFileIds = array_merge($allFileIds, $tagFileIds);
                }
                return array_unique($allFileIds);
            }
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getTagIdByName($tagName): ?string
    {
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

    private function matchesFileType($file, $fileType): bool
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

    private function getExtensionsForFileType($fileType): array
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

    private function fileMatchesTags($fileId, $tags, $tagOperator): bool
    {
        $fileTags = $this->getFileTags($fileId);
        $fileTagNames = array_column($fileTags, 'name');

        $matches = array_intersect($tags, $fileTagNames);

        if ($tagOperator === 'AND') {
            return count($matches) === count($tags);
        } else {
            return count($matches) > 0;
        }
    }

    private function formatFileResult($file): array
    {
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

    private function getFileTags($fileId): array
    {
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
