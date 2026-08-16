# Notes de développement

*[English version](DEVELOPMENT.md)*

Notes techniques détaillées pour contribuer à l'appli — pièges rencontrés,
déploiement de développement, structure interne. Pour une présentation
générale des fonctionnalités, voir [`README.fr.md`](README.fr.md)
([English version](README.md)).

## Build frontend

```bash
cd apps/tickets
npm install
npm run build      # build unique
npm run watch       # rebuild automatique à chaque modification
```

### CSP (Content Security Policy)

Nextcloud bloque `eval()` dans ses scripts (`script-src` sans
`unsafe-eval`). Le `devtool` par défaut de webpack en mode développement
(`eval`) déclenche ce blocage. `webpack.config.js` force donc
`devtool: 'source-map'`, qui ne génère pas de code évalué dynamiquement.

### Fond bleu flouté au lieu d'un fond blanc

Le thème Hub de Nextcloud affiche un fond flouté par défaut derrière tout
contenu qui n'a pas son propre arrière-plan. Le composant `App.vue` fixe
donc explicitement `background-color: var(--color-main-background)` sur son
conteneur racine.

## Déploiement Docker (NAS Synology)

Le développement/build passe par un conteneur `node-dev` dédié dans le
`docker-compose.yml`, avec `/app/node_modules` monté en volume anonyme pour
ne pas être écrasé par le bind mount du code source :

```yaml
node-dev:
  container_name: Nextcloud-dev
  image: node:20
  working_dir: /app
  environment:
    - HOME=/tmp
  volumes:
    - /volume1/docker/nextcloud/custom_apps/tickets:/app
    - /app/node_modules
  command: sh -c "npm install --legacy-peer-deps && npm run watch"
```

**Piège rencontré** : ce conteneur ne doit pas tourner avec
`user: "82:82"` (uid de `www-data` utilisé par le conteneur Nextcloud) —
Docker crée le volume anonyme `node_modules` appartenant à `root`, donc
`npm install` échoue silencieusement en écriture avec un autre utilisateur,
laissant des liens `.bin/` cassés (`webpack: not found`).

En cas de build qui semble bloqué ou incohérent, repartir propre :
```bash
docker compose stop node-dev
rm -rf /volume1/docker/nextcloud/custom_apps/tickets/node_modules
rm -rf /volume1/docker/nextcloud/custom_apps/tickets/js
rm -rf /volume1/docker/nextcloud/custom_apps/tickets/css
docker compose up -d node-dev
docker compose logs -f node-dev
```

## Numéro de ticket

Identifiant lisible du type `TCK-2026-00007`, calculé à la volée côté
backend (`Ticket::getTicketNumber()`) à partir de l'id auto-incrémenté et de
l'année de création — pas de compteur séparé à maintenir, donc pas de
risque de doublon en cas d'accès concurrent.

## Icônes

`img/app.svg` (icône claire, fond sombre du lanceur d'applications) est
auto-recolorée par CSS selon le contexte (top nav). `img/app-dark.svg` est
utilisé automatiquement par le cœur de Nextcloud (`AppManager::getAppIcon()`)
dans les contextes qui n'appliquent pas ce recoloriage (ex. Réglages →
Applications) — pas de configuration `info.xml` nécessaire, la résolution
se fait par convention de nom (`app-dark.svg`).

## Langue

Interface écrite en anglais (chaînes source), avec traduction française.
**Deux fichiers sont nécessaires par langue** dans `l10n/` :
- `l10n/<code>.json` — utilisé côté serveur (PHP, `$l->t()`)
- `l10n/<code>.js` — utilisé côté client (Vue, `t('tickets', ...)`), chargé
  via l'appel `Util::addTranslations('tickets')` dans `PageController`.

**Piège rencontré** : sans le fichier `.js` (le `.json` seul ne suffit pas
côté client), l'interface reste en anglais même avec un compte configuré en
français — `Util::addTranslations()` génère une balise `<script>` pointant
vers `l10n/<lang>.js`, silencieusement absente si le fichier n'existe pas.

Pour ajouter une langue : dupliquer les deux fichiers `fr.json`/`fr.js` vers
`<code>.json`/`<code>.js` et traduire les valeurs (garder les clés en
anglais, identiques aux chaînes sources utilisées dans le code).

## Structure

- `lib/Db/` — entités et mappers (`Ticket`, `Comment`, `Attachment`,
  `Activity`, `TicketRead`)
- `lib/Controller/` — API REST (`TicketController`, `SettingsController`)
  et page (`PageController`)
- `lib/Service/` — logique métier (`AttachmentService`, `ConfigService`,
  `MailService`, `NotificationService`, `XlsxWriter`)
- `lib/Migration/` — création des tables SQL (valeurs en anglais : catégories
  `plumbing`/`elevator`/`common_areas`/`nuisance`/`other`, statuts
  `new`/`in_progress`/`resolved`/`closed`, priorités `low`/`normal`/`urgent`)
- `src/App.vue` — interface complète (bouton + modale de création, liste,
  modale de détail avec commentaires)
- `l10n/` — traductions (voir section Langue ci-dessus)
- `appinfo/routes.php` — routes API et page
- `appinfo/info.xml` — métadonnées de l'appli (nom, version, compatibilité)

## Versioning

**La version dans `appinfo/info.xml` doit être incrémentée à chaque
modification livrée.** Nextcloud compare cette version à celle enregistrée
en base pour décider si les migrations doivent être rejouées lors de
`occ app:enable` / `occ upgrade` — sans incrément, une migration modifiée ou
ajoutée peut ne jamais s'exécuter.

## Tests

Tests unitaires PHPUnit dans `tests/Unit/` :
- `tests/Unit/Service/` — `NotificationServiceTest`, `MailServiceTest`
  (dédup des destinataires, garde-fous auteur/acteur), `AttachmentServiceTest`
  (extensions autorisées, rangement par statut, suppressions en cascade),
  purement mockés.
- `tests/Unit/Db/` — `TicketReadMapperTest` tourne contre une vraie
  connexion DB pour couvrir le upsert manuel select-puis-insert/update.
- `tests/Unit/Controller/` — contrôle d'accès par rôle, cycle de vie d'un
  ticket, remise à zéro complète (`SettingsControllerTest`,
  `TicketControllerTest`, `PageControllerTest`).

Comme toute appli Nextcloud, ces tests mockent des interfaces `OCP\*` et
étendent `\Test\TestCase`, fournis par le cœur du serveur : l'appli doit donc
être placée dans `apps/` (ou `apps-extra/`) d'une installation Nextcloud
complète pour pouvoir les lancer (`composer install` puis `composer
test:unit` depuis le dossier de l'appli, ou `NEXTCLOUD_ROOT=/chemin/serveur`
si elle n'est pas à son emplacement standard). Voir `tests/bootstrap.php`.
