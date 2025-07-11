<?php
namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCA\AdvancedSearch\Service\SearchService;

class SearchController extends Controller {
    private $searchService;

    public function __construct($AppName, IRequest $request, SearchService $searchService) {
        parent::__construct($AppName, $request);
        $this->searchService = $searchService;
    }

    #[NoAdminRequired]
    public function search($filename = '', $tags = [], $tagOperator = 'AND', $fileType = '') {
        try {
            $results = $this->searchService->searchFiles($filename, $tags, $tagOperator, $fileType);
            return new JSONResponse(['success' => true, 'files' => $results]);
        } catch (\Exception $e) {
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}