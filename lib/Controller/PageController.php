<?php
namespace OCA\AdvancedSearch\Controller;

use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    
    protected $appName;
   private $eventDispatcher;
   
   public function __construct($appName,
								IRequest $request,
								IEventDispatcher $eventDispatcher) {
		parent::__construct($appName, $request);

		$this->appName = $appName;
		$this->eventDispatcher = $eventDispatcher;
	}

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index() {
        // Adicionar scripts e estilos
        $this->eventDispatcher->dispatch(LoadViewer::class, new LoadViewer());
        Util::addScript('advancedsearch', 'search');
        Util::addStyle('advancedsearch', 'style');
        
        return new TemplateResponse('advancedsearch', 'index');
    }
}