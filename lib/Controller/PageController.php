<?php
namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    
    public function __construct($AppName, IRequest $request) {
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index() {
        // Adicionar scripts e estilos
        Util::addScript('advanced-search', 'search');
        Util::addStyle('advanced-search', 'style');
        
        return new TemplateResponse('advanced-search', 'index');
    }
}