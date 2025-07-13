<?php
namespace OCA\AdvancedSearch;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\AdvancedSearch\Service\SearchService;
use OCP\FullTextSearch\IFullTextSearchManager;

class Application extends App implements IBootstrap {
    public const APP_ID = 'advancedsearch';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Registrar serviços se necessário
        $context->registerService(SearchService::class, function($c) {
            $server = $c->getServerContainer();
            
            // Tentar obter o FullTextSearchManager
            $fullTextSearchManager = null;
            try {
                if ($server->getAppManager()->isEnabledForUser('fulltextsearch')) {
                    $fullTextSearchManager = $server->get(IFullTextSearchManager::class);
                }
            } catch (\Exception $e) {
                // FullTextSearch não está disponível
                $fullTextSearchManager = null;
            }
            
            return new SearchService(
                $server->getRootFolder(),
                $server->getUserSession(),
                $server->getSystemTagManager(),
                $server->getSystemTagObjectMapper(),
                $server->getAppManager(),
                $fullTextSearchManager
            );
        });
    }

    public function boot(IBootContext $context): void {
        // Boot logic se necessário
    }
}