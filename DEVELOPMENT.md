# Development notes

*[Version française](DEVELOPMENT.fr.md)*

Detailed technical notes for contributing to the app — pitfalls
encountered, development deployment, internal structure. For a general
overview of the features, see [`README.md`](README.md)
([French version](README.fr.md)).

## Frontend build

```bash
cd apps/tickets
npm install
npm run build      # one-off build
npm run watch       # automatic rebuild on every change
```

### CSP (Content Security Policy)

Nextcloud blocks `eval()` in its scripts (`script-src` without
`unsafe-eval`). Webpack's default `devtool` in development mode (`eval`)
triggers this block. `webpack.config.js` therefore forces
`devtool: 'source-map'`, which doesn't generate dynamically evaluated code.

### Blurred blue background instead of a white one

Nextcloud's Hub theme shows a blurred background by default behind any
content that doesn't set its own background. The `App.vue` component
therefore explicitly sets `background-color: var(--color-main-background)`
on its root container.

## Docker deployment (Synology NAS)

Development/build goes through a dedicated `node-dev` container in
`docker-compose.yml`, with `/app/node_modules` mounted as an anonymous
volume so it isn't overwritten by the source code bind mount:

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

**Pitfall encountered**: this container must not run with
`user: "82:82"` (the `www-data` uid used by the Nextcloud container) —
Docker creates the anonymous `node_modules` volume owned by `root`, so
`npm install` silently fails to write as a different user, leaving broken
`.bin/` links (`webpack: not found`).

If a build seems stuck or inconsistent, start clean:
```bash
docker compose stop node-dev
rm -rf /volume1/docker/nextcloud/custom_apps/tickets/node_modules
rm -rf /volume1/docker/nextcloud/custom_apps/tickets/js
rm -rf /volume1/docker/nextcloud/custom_apps/tickets/css
docker compose up -d node-dev
docker compose logs -f node-dev
```

## Ticket number

A human-readable identifier like `TCK-2026-00007`, computed on the fly on
the backend (`Ticket::getTicketNumber()`) from the auto-incremented id and
the creation year — no separate counter to maintain, so no risk of
duplicates under concurrent access.

## Icons

`img/app.svg` (light icon, for the app launcher's dark background) is
auto-recolored by CSS depending on context (top nav). `img/app-dark.svg` is
used automatically by Nextcloud core (`AppManager::getAppIcon()`) in
contexts that don't apply that recoloring (e.g. Settings → Apps) — no
`info.xml` configuration needed, resolution happens by naming convention
(`app-dark.svg`).

## Language

Interface written in English (source strings), with a French translation.
**Two files are needed per language** in `l10n/`:
- `l10n/<code>.json` — used server-side (PHP, `$l->t()`)
- `l10n/<code>.js` — used client-side (Vue, `t('tickets', ...)`), loaded
  via the `Util::addTranslations('tickets')` call in `PageController`.

**Pitfall encountered**: without the `.js` file (the `.json` alone isn't
enough client-side), the interface stays in English even with an account
set to French — `Util::addTranslations()` generates a `<script>` tag
pointing to `l10n/<lang>.js`, silently missing if the file doesn't exist.

To add a language: duplicate the `fr.json`/`fr.js` files to
`<code>.json`/`<code>.js` and translate the values (keep the keys in
English, matching the source strings used in the code).

## Structure

- `lib/Db/` — entities and mappers (`Ticket`, `Comment`, `Attachment`,
  `Activity`, `TicketRead`)
- `lib/Controller/` — REST API (`TicketController`, `SettingsController`)
  and page controller (`PageController`)
- `lib/Service/` — business logic (`AttachmentService`, `ConfigService`,
  `MailService`, `NotificationService`, `XlsxWriter`)
- `lib/Migration/` — SQL table creation (English values: categories
  `plumbing`/`elevator`/`common_areas`/`nuisance`/`other`, statuses
  `new`/`in_progress`/`resolved`/`closed`, priorities `low`/`normal`/`urgent`)
- `src/App.vue` — full interface (creation button + modal, list, detail
  modal with comments)
- `l10n/` — translations (see Language section above)
- `appinfo/routes.php` — API and page routes
- `appinfo/info.xml` — app metadata (name, version, compatibility)

## Versioning

**The version in `appinfo/info.xml` must be bumped on every shipped
change.** Nextcloud compares this version to the one stored in the
database to decide whether migrations need to be replayed during
`occ app:enable` / `occ upgrade` — without a bump, a modified or added
migration might never run.

## Tests

PHPUnit unit tests in `tests/Unit/`:
- `tests/Unit/Service/` — `NotificationServiceTest`, `MailServiceTest`
  (recipient dedup, author/actor guards), `AttachmentServiceTest`
  (allowed extensions, filing by status, cascading deletions), fully
  mocked.
- `tests/Unit/Db/` — `TicketReadMapperTest` runs against a real DB
  connection to cover the manual select-then-insert/update upsert.
- `tests/Unit/Controller/` — role-based access control, a ticket's
  lifecycle, full reset (`SettingsControllerTest`, `TicketControllerTest`,
  `PageControllerTest`).

Like any Nextcloud app, these tests mock `OCP\*` interfaces and extend
`\Test\TestCase`, provided by the server core: the app therefore needs to
be placed under `apps/` (or `apps-extra/`) of a full Nextcloud installation
to run them (`composer install` then `composer test:unit` from the app's
folder, or `NEXTCLOUD_ROOT=/server/path` if it isn't in its standard
location). See `tests/bootstrap.php`.
