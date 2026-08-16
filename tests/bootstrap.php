<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit pour l'appli "tickets".
 *
 * Comme toute appli Nextcloud, ces tests unitaires mockent des interfaces
 * OCP\* (IDBConnection, INotificationManager, IMailer...) et étendent
 * \Test\TestCase : ces deux éléments sont fournis par le cœur du serveur
 * Nextcloud, pas par un paquet composer installable indépendamment. Il faut
 * donc que cette appli soit placée dans apps/ (ou apps-extra/) d'une
 * installation Nextcloud complète pour que les tests puissent tourner —
 * exactement comme n'importe quelle autre appli du store.
 *
 * Lancement (depuis la racine du serveur, ou avec le conteneur de dev
 * habituel type nextcloud-docker-dev) :
 *   php occ.php ... # sans objet ici
 *   vendor/bin/phpunit -c apps/tickets/phpunit.xml
 * ou, depuis le dossier de l'appli elle-même si server/apps/tickets :
 *   phpunit -c phpunit.xml
 *
 * NEXTCLOUD_ROOT permet de pointer vers un autre emplacement du serveur si
 * l'appli n'est pas déployée à sa place standard (ex. lien symbolique en
 * cours de développement).
 */

define('PHPUNIT_RUN', 1);

$appId = 'tickets';

$serverRoot = getenv('NEXTCLOUD_ROOT');
if ($serverRoot === false || $serverRoot === '') {
    // Emplacement standard : .../server/apps/tickets/tests/bootstrap.php
    // -> racine du serveur trois niveaux au-dessus.
    $serverRoot = __DIR__ . '/../../..';
}
$serverRoot = rtrim($serverRoot, '/');

if (!file_exists($serverRoot . '/lib/base.php')) {
    fwrite(STDERR,
        "Impossible de trouver lib/base.php sous \"$serverRoot\".\n" .
        "Ces tests doivent tourner depuis une installation Nextcloud complète, " .
        "avec l'appli \"tickets\" placée dans apps/ (ou apps-extra/).\n" .
        "Utilisez la variable d'environnement NEXTCLOUD_ROOT si le serveur " .
        "se trouve ailleurs.\n"
    );
    exit(1);
}

require_once $serverRoot . '/lib/base.php';

// Charge l'appli (routes, DI...) comme le ferait le serveur en production.
\OC_App::loadApp($appId);

// Utilitaires de test du serveur : fournit notamment \Test\TestCase.
require_once $serverRoot . '/tests/autoload.php';

// Autoloader composer propre à l'appli (namespace OCA\Tickets\Tests\* ici).
require_once __DIR__ . '/../vendor/autoload.php';
