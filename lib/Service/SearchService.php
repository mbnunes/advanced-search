<?php
namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchQuery;
use OCP\Files\Search\SearchBinaryOperator;
use OCP\Files\Search\SearchComparison;
use OCP\Files\Search\SearchQuery;

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

    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0) {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not logged in');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        
        // Se só temos busca por tags, usar método diferente
        if (empty($filename) && empty($fileType) && !empty($tags)) {
            return $this->searchByTagsOnly($tags, $tagOperator, $limit, $offset);
        }
        
        // Construir query de busca
        $searchQuery = $this->buildSearchQuery($filename, $fileType, $limit, $offset);
        
        // Executar busca usando a API do Nextcloud
        $searchResults = $userFolder->search($searchQuery);
        
        // Processar e formatar resultados
        $results = [];
        foreach ($searchResults as $file) {
            // Filtrar por tags se necessário
            if (!empty($tags)) {
                if (!$this->fileMatchesTags($file->getId(), $tags, $tagOperator)) {
                    continue;
                }
            }
            
            $results[] = $this->formatFileResult($file);
        }

        return $results;
    }

    private function searchByTagsOnly($tags, $tagOperator, $limit, $offset) {
        $user = $this->userSession->getUser();
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        
        // Buscar IDs dos arquivos que têm as tags
        $fileIds = $this->getFileIdsByTags($tags, $tagOperator);
        
        // Converter IDs para objetos de arquivo
        $results = [];
        $count = 0;
        
        foreach ($fileIds as $fileId) {
            if ($count >= $offset && count($results) < $limit) {
                try {
                    $file = $userFolder->getById($fileId);
                    if (!empty($file) && $file[0]->getType() === 'file') {
                        $results[] = $this->formatFileResult($file[0]);
                    }
                } catch (\Exception $e) {
                    // Arquivo não encontrado ou sem permissão
                    continue;
                }
            }
            $count++;
        }
        
        return $results;
    }

    private function getFileIdsByTags($tags, $operator) {
        $fileIds = [];
        
        if ($operator === 'AND') {
            // Para AND, começamos com arquivos da primeira tag
            $firstTag = array_shift($tags);
            $tagId = $this->getTagIdByName($firstTag);
            
            if ($tagId) {
                $fileIds = $this->systemTagObjectMapper->getObjectsForTag($tagId, 'files');
                
                // Interseção com outras tags
                foreach ($tags as $tagName) {
                    $tagId = $this->getTagIdByName($tagName);
                    if ($tagId) {
                        $otherFileIds = $this->systemTagObjectMapper->getObjectsForTag($tagId, 'files');
                        $fileIds = array_intersect($fileIds, $otherFileIds);
                    } else {
                        // Tag não encontrada = resultado vazio
                        return [];
                    }
                }
            }
        } else { // OR
            // Para OR, união de todas as tags
            foreach ($tags as $tagName) {
                $tagId = $this->getTagIdByName($tagName);
                if ($tagId) {
                    $tagFileIds = $this->systemTagObjectMapper->getObjectsForTag($tagId, 'files');
                    $fileIds = array_merge($fileIds, $tagFileIds);
                }
            }
            $fileIds = array_unique($fileIds);
        }
        
        return $fileIds;
    }

    private function getTagIdByName($tagName) {
        try {
            $tags = $this->systemTagManager->getTagsById([]);
            foreach ($tags as $tag) {
                if ($tag->getName() === $tagName) {
                    return $tag->getId();
                }
            }
            
            // Buscar de forma mais eficiente
            $tagsByName = $this->systemTagManager->getTagsByName($tagName);
            return !empty($tagsByName) ? $tagsByName[0]->getId() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function buildSearchQuery($filename, $fileType, $limit, $offset) {
        $conditions = [];
        
        // Busca por nome de arquivo
        if (!empty($filename)) {
            $conditions[] = new SearchComparison(
                ISearchComparison::COMPARE_LIKE,
                'name',
                '%' . $filename . '%'
            );
        }
        
        // Busca por tipo de arquivo (mimetype)
        if (!empty($fileType)) {
            $mimetypes = $this->getMimetypesForFileType($fileType);
            if (!empty($mimetypes)) {
                $mimetypeConditions = [];
                foreach ($mimetypes as $mimetype) {
                    $mimetypeConditions[] = new SearchComparison(
                        ISearchComparison::COMPARE_LIKE,
                        'mimetype',
                        $mimetype
                    );
                }
                
                if (count($mimetypeConditions) > 1) {
                    $conditions[] = new SearchBinaryOperator(
                        ISearchBinaryOperator::OPERATOR_OR,
                        $mimetypeConditions
                    );
                } else {
                    $conditions[] = $mimetypeConditions[0];
                }
            }
        }
        
        // Garantir que só buscamos arquivos (não pastas)
        $conditions[] = new SearchComparison(
            ISearchComparison::COMPARE_EQUAL,
            'type',
            'file'
        );
        
        // Combinar todas as condições
        if (count($conditions) === 1) {
            $finalCondition = $conditions[0];
        } else {
            $finalCondition = new SearchBinaryOperator(
                ISearchBinaryOperator::OPERATOR_AND,
                $conditions
            );
        }
        
        return new SearchQuery(
            $finalCondition,
            $limit,
            $offset,
            [
                'name' => ISearchQuery::SORT_ASC
            ]
        );
    }
    
    private function getMimetypesForFileType($fileType) {
        switch ($fileType) {
            case 'image':
                return ['image/%'];
            case 'document':
                return [
                    'text/%',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml%',
                    'application/vnd.oasis.opendocument.text',
                    'application/rtf'
                ];
            case 'video':
                return ['video/%'];
            case 'audio':
                return ['audio/%'];
            case 'pdf':
                return ['application/pdf'];
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