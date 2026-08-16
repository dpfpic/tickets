<?php

declare(strict_types=1);

return [
    'routes' => [
        // Page principale (SPA Vue)
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // API tickets
        ['name' => 'ticket#index', 'url' => '/api/tickets', 'verb' => 'GET'],
        // Route statique déclarée avant 'ticket#show' (qui capte /api/tickets/{id}) pour
        // être bien matchée en priorité sur '/api/tickets/export'.
        ['name' => 'ticket#exportTickets', 'url' => '/api/tickets/export', 'verb' => 'GET'],
        ['name' => 'ticket#show', 'url' => '/api/tickets/{id}', 'verb' => 'GET'],
        ['name' => 'ticket#create', 'url' => '/api/tickets', 'verb' => 'POST'],
        ['name' => 'ticket#update', 'url' => '/api/tickets/{id}', 'verb' => 'PUT'],
        ['name' => 'ticket#destroy', 'url' => '/api/tickets/{id}', 'verb' => 'DELETE'],

        // Commentaires
        ['name' => 'ticket#addComment', 'url' => '/api/tickets/{id}/comments', 'verb' => 'POST'],

        // Pièces jointes
        ['name' => 'ticket#addAttachment', 'url' => '/api/tickets/{id}/attachments', 'verb' => 'POST'],
        ['name' => 'ticket#downloadAttachment', 'url' => '/api/tickets/{id}/attachments/{attachmentId}', 'verb' => 'GET'],
        ['name' => 'ticket#deleteAttachment', 'url' => '/api/tickets/{id}/attachments/{attachmentId}', 'verb' => 'DELETE'],
        ['name' => 'ticket#attachmentsFolder', 'url' => '/api/tickets/{id}/attachments-folder', 'verb' => 'GET'],

        // Infos utilisateur courant (membre du groupe gestionnaire ou non)
        ['name' => 'ticket#context', 'url' => '/api/context', 'verb' => 'GET'],

        // Réglages admin : choix des groupes demandeurs / gestionnaires
        ['name' => 'settings#groups', 'url' => '/api/admin/groups', 'verb' => 'GET'],
        ['name' => 'settings#getConfig', 'url' => '/api/admin/config', 'verb' => 'GET'],
        ['name' => 'settings#saveConfig', 'url' => '/api/admin/config', 'verb' => 'POST'],

        // Import / export des catégories (fichier JSON)
        ['name' => 'settings#exportCategories', 'url' => '/api/admin/categories/export', 'verb' => 'GET'],
        ['name' => 'settings#importCategories', 'url' => '/api/admin/categories/import', 'verb' => 'POST'],

        // Sauvegarde et maintenance
        ['name' => 'settings#exportTickets', 'url' => '/api/admin/tickets/export', 'verb' => 'GET'],
        ['name' => 'settings#reset', 'url' => '/api/admin/reset', 'verb' => 'POST'],
    ],
];
