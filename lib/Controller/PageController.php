<?php
namespace OCA\AdvancedSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    
    public function __construct($AppName, IRequest $request) {
        parent::__construct($AppName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index() {
        // Adicionar scripts e estilos
        Util::addScript('advancedsearch', 'search');
        Util::addStyle('advancedsearch', 'style');
        
        return new TemplateResponse('advancedsearch', 'index');
    }
}