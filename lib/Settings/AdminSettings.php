<?php

declare(strict_types=1);

namespace OCA\Tickets\Settings;

use OCA\Tickets\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'tickets-admin');
        Util::addStyle(Application::APP_ID, 'tickets-admin');

        return new TemplateResponse(Application::APP_ID, 'admin', [], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
