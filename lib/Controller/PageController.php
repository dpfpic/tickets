<?php

declare(strict_types=1);

namespace OCA\Tickets\Controller;

use OCA\Tickets\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Sans cet appel, l10n/<lang>.json n'est jamais chargé côté client
        // et t('tickets', ...) retombe sur la chaîne source (anglais).
        Util::addTranslations(Application::APP_ID);

        $response = new TemplateResponse(Application::APP_ID, 'main');
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedConnectDomain('*');
        $response->setContentSecurityPolicy($csp);
        return $response;
    }
}
