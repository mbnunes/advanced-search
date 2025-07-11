<?php

namespace OCA\AdvancedSearch\Service;

use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Node;
use OCP\Files\FileInfo;
use OCP\IDBConnection;

class SearchService
{
    private $rootFolder;
    private $userSession;
    private $systemTagManager;
    private $systemTagObjectMapper;
    private $db;
    private $ftsManager;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagObjectMapper,
        IDBConnection $db,
        \OCP\FullTextSearch\IFullTextSearchManager $ftsManager // novo!
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->systemTagManager = $systemTagManager;
        $this->systemTagObjectMapper = $systemTagObjectMapper;
        $this->db = $db;
        $this->ftsManager = $ftsManager;
    }


    public function searchFiles($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '', $limit = 100, $offset = 0)
    {
        $user = $this->userSession->getUser();
        if (!$user) throw new \Exception('User not logged in');
        $userId = $user->getUID();
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $results = [];
        $fileIds = [];

        // Caso 1: Nome E tags (usa Elasticsearch!)
        if (!empty($filename) && !empty($tags)) {
            $ftsQuery = $filename;
            foreach ($tags as $tag) {
                $ftsQuery .= ' tag:"' . $tag . '"'; // Aspas ajudam para tags com espaço
            }
            if ($fileType) {
                // Adicione solução para mimetype, se necessário
            }
            try {
                $searchResults = $this->ftsManager->search(
                    $ftsQuery,
                    $user,
                    $limit,
                    $offset,
                    ['files']
                );
                $documents = $searchResults->getDocuments();
                foreach ($documents as $doc) {
                    $fileIds[] = $doc->getId();
                }
            } catch (\Exception $e) {
                error_log('[AdvancedSearch] Elasticsearch erro nome+tag: ' . $e->getMessage());
                return ['files' => [], 'total' => 0];
            }
            // Caso 2: Só nome (usa Elastic)
        } else if (!empty($filename)) {
            $ftsQuery = $filename;
            if ($fileType) {
                // Adicione mimetype se quiser
            }
            try {
                $searchResults = $this->ftsManager->search(
                    $ftsQuery,
                    $user,
                    $limit,
                    $offset,
                    ['files']
                );
                $documents = $searchResults->getDocuments();
                foreach ($documents as $doc) {
                    $fileIds[] = $doc->getId();
                }
            } catch (\Exception $e) {
                error_log('[AdvancedSearch] Elasticsearch erro nome: ' . $e->getMessage());
                return ['files' => [], 'total' => 0];
            }
            // Caso 3: Só tags (usa método por tags puro)
        } else if (!empty($tags)) {
            $fileIds = $this->getFileIdsByTags($tags, $tagOperator);
            // Caso 4: Só tipo
        } else if (!empty($fileType)) {
            $fileIds = $this->searchFilesByTypeSQL($userId, $fileType, $limit, $offset);
            // Caso 5: Nada (opcional: recentes)
        } else {
            $fileIds = $this->getRecentFilesSQL($userId, $limit, $offset);
        }

        // Monta resultados
        foreach ($fileIds as $fileId) {
            try {
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    $file = $nodes[0];
                    $results[] = $this->formatFileResult($file);
                }
            } catch (\Exception $e) {
                // error_log('Erro getById: ' . $e->getMessage());
                continue;
            }
        }
        return ['files' => $results, 'total' => count($results)];
    }

    private function searchFilesByNameSQL($userId, $filename, $limit, $offset)
    {
        $qb = $this->db->getQueryBuilder();

        // Buscar na tabela filecache usando LIKE para performance
        $qb->select('filecache.fileid')
            ->from('filecache')
            ->innerJoin('filecache', 'storages', 'storages', 'filecache.storage = storages.numeric_id')
            ->where($qb->expr()->like('filecache.name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filename) . '%')))
            ->andWhere($qb->expr()->eq('filecache.mimetype', $qb->createNamedParameter(2, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->like('storages.id', $qb->createNamedParameter('%:' . $userId . '%')))
            ->orderBy('filecache.mtime', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->execute();
        $fileIds = [];

        while ($row = $result->fetch()) {
            $fileIds[] = (int)$row['fileid'];
        }

        return $fileIds;
    }

    private function searchFilesByTypeSQL($userId, $fileType, $limit, $offset)
    {
        $qb = $this->db->getQueryBuilder();

        // Tipos de mimetype correspondentes
        $mimeTypePattern = $this->getMimeTypePattern($fileType);

        $qb->select('filecache.fileid')
            ->from('filecache')
            ->innerJoin('filecache', 'storages', 'storages', 'filecache.storage = storages.numeric_id')
            ->innerJoin('filecache', 'mimetypes', 'mimetypes', 'filecache.mimetype = mimetypes.id')
            ->where($qb->expr()->like('mimetypes.mimetype', $qb->createNamedParameter($mimeTypePattern)))
            ->andWhere($qb->expr()->like('storages.id', $qb->createNamedParameter('%:' . $userId . '%')))
            ->orderBy('filecache.mtime', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->execute();
        $fileIds = [];

        while ($row = $result->fetch()) {
            $fileIds[] = (int)$row['fileid'];
        }

        return $fileIds;
    }

    private function getRecentFilesSQL($userId, $limit, $offset)
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('filecache.fileid')
            ->from('filecache')
            ->innerJoin('filecache', 'storages', 'storages', 'filecache.storage = storages.numeric_id')
            ->where($qb->expr()->eq('filecache.mimetype', $qb->createNamedParameter(2, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->like('storages.id', $qb->createNamedParameter('%:' . $userId . '%')))
            ->orderBy('filecache.mtime', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->execute();
        $fileIds = [];

        while ($row = $result->fetch()) {
            $fileIds[] = (int)$row['fileid'];
        }

        return $fileIds;
    }

    private function getMimeTypePattern($fileType)
    {
        switch ($fileType) {
            case 'image':
                return 'image/%';
            case 'document':
                return 'text/%';
            case 'video':
                return 'video/%';
            case 'audio':
                return 'audio/%';
            case 'pdf':
                return 'application/pdf';
            default:
                return '%';
        }
    }

    // private function searchByOtherCriteria($userFolder, $fileType, $tags, $tagOperator) {
    //     // Se só temos busca por tags, usar método específico
    //     if (!empty($tags) && empty($fileType)) {
    //         return $this->searchByTagsOnly($userFolder, $tags, $tagOperator);
    //     }

    //     // Se só temos busca por tipo de arquivo, buscar por extensão comum
    //     if (!empty($fileType) && empty($tags)) {
    //         return $this->searchByFileTypeOnly($userFolder, $fileType);
    //     }

    //     // Busca geral - pegar arquivos recentes como fallback
    //     try {
    //         return $userFolder->getRecent(1000);
    //     } catch (\Exception $e) {
    //         // Se getRecent não funcionar, retornar array vazio
    //         return [];
    //     }
    // }

    // private function searchByTagsOnly($userFolder, $tags, $tagOperator) {
    //     $fileIds = $this->getFileIdsByTags($tags, $tagOperator);
    //     $files = [];

    //     foreach ($fileIds as $fileId) {
    //         try {
    //             $fileNodes = $userFolder->getById($fileId);
    //             if (!empty($fileNodes)) {
    //                 $files[] = $fileNodes[0];
    //             }
    //         } catch (\Exception $e) {
    //             // Arquivo não encontrado ou sem permissão
    //             continue;
    //         }
    //     }

    //     return $files;
    // }

    // private function searchByFileTypeOnly($userFolder, $fileType) {
    //     $extensions = $this->getExtensionsForFileType($fileType);
    //     $files = [];

    //     foreach ($extensions as $extension) {
    //         try {
    //             // Buscar com ponto antes da extensão
    //             $searchResults = $userFolder->search('.' . $extension);
    //             foreach ($searchResults as $result) {
    //                 // Verificar se realmente termina com a extensão
    //                 if (strtolower(pathinfo($result->getName(), PATHINFO_EXTENSION)) === $extension) {
    //                     $files[] = $result;
    //                 }
    //             }
    //         } catch (\Exception $e) {
    //             continue;
    //         }
    //     }

    //     return $files;
    // }

    private function getFileIdsByTags($tags, $operator)
    {
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

    private function getTagIdByName($tagName)
    {
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

    // private function getExtensionsForFileType($fileType) {
    //     switch ($fileType) {
    //         case 'image':
    //             return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];

    //         case 'document':
    //             return ['doc', 'docx', 'odt', 'txt', 'rtf', 'md'];

    //         case 'video':
    //             return ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'];

    //         case 'audio':
    //             return ['mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a'];

    //         case 'pdf':
    //             return ['pdf'];

    //         default:
    //             return [];
    //     }
    // }

    private function fileMatchesTags($fileId, $tags, $tagOperator)
    {
        $fileTags = $this->getFileTags($fileId);
        $fileTagNames = array_column($fileTags, 'name');

        $matches = array_intersect($tags, $fileTagNames);

        if ($tagOperator === 'AND') {
            return count($matches) === count($tags);
        } else { // OR
            return count($matches) > 0;
        }
    }

    private function formatFileResult($file)
    {
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
    private function getFileTags($fileId)
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
