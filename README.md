# 🎫 Tickets — request management for Nextcloud

*[Version française](README.fr.md)*

**Tickets** is a simple [Nextcloud](https://nextcloud.com) app for
managing requests/tickets: users submit requests, a manager group tracks,
prioritizes, and responds to them from a dedicated board.

The app isn't tied to any specific use case: it works just as well for a
homeowners' association (maintenance requests) as for an internal company
help desk, an HR department, or any organization that needs a simple entry
point to centralize requests. The groups allowed to submit or manage
requests, as well as the ticket categories, are fully configurable from the
admin settings.

- **Author**: DPFPIC
- **License**: [AGPL-3.0-or-later](LICENSE)
- **Compatibility**: Nextcloud 27 to 34

## Table of contents

- [Features](#features)
- [Roles and permissions](#roles-and-permissions)
- [Notifications](#notifications)
- [Security and privacy](#security-and-privacy)
- [Installation](#installation)
- [Configuration](#configuration)
- [Internationalization](#internationalization)
- [Development](#development)
- [License](#license)

## Features

- **One-click request creation**, with title, description, category,
  priority, and detection of potential duplicates (a title close to an
  already-open ticket from the same requester, with the option to confirm
  anyway).
- **Filterable tracking board** (status, priority, category, assignee),
  sortable by column, with clickable counters per status
  (All / New / In progress / Resolved / Closed).
- **Priorities and statuses readable without relying on color**: each
  priority level also carries a shape icon, to stay accessible.
- **Detail view** per ticket: description, comment thread, timestamped
  activity log (status, priority, assignment, and due-date changes,
  attachments), and a reply form.
- **Attachments** via file picker or drag-and-drop, with:
  - a list of allowed extensions and a maximum size, both configurable by
    the admin;
  - storage in a dedicated Nextcloud account, automatically organized by
    ticket (and by status: active / resolved / closed);
  - automatic compatibility with an antivirus already installed on the
    instance ([Files_Antivirus](https://apps.nextcloud.com/apps/files_antivirus)).
- **Due dates** ("to be handled before") with configurable automatic
  reminders as the date approaches or is missed.
- **Excel export**: one-off export of the filtered board view for the
  manager group, and a full export (including comments) reserved for the
  admin, for archiving/reporting.
- **In-app and email notifications** at key steps of a ticket's lifecycle
  (see [Notifications](#notifications)).
- **Per-user "unread" marker**: badge and bold styling on tickets with a
  new message since the last visit.
- **Mobile card view**: the table automatically reflows below 640px wide.
- **Customizable categories** (French/English labels), JSON export/import
  to duplicate them from one instance to another.
- **GDPR compliance**: personal data export and cascading deletion on
  account closure, via Nextcloud's standard mechanisms (see
  [Security and privacy](#security-and-privacy)).
- **Bilingual interface** in French/English, automatically detected from
  the Nextcloud account's language.

## Roles and permissions

| Role | Can do |
|---|---|
| **Requester** (any logged-in user, or member of a "requester" group if configured) | Create a request, view and edit their own tickets while still at "New" status, comment, manage their own attachments |
| **Manager group** | View all tickets, change status/priority/assignment/due date, correct the requester's name/location, delete a ticket, export to Excel, configure the app |

The "manager" and "requester" groups are chosen from
**Admin settings → Tickets**, among existing Nextcloud groups.

## Notifications

Every notable event triggers an in-app notification (bell) and, for some
events, an email via the SMTP server already configured on the instance:

| Event | Bell | Email |
|---|---|---|
| Ticket created | manager group | manager mailbox + requester |
| Taken in charge (assignment) | — | manager mailbox |
| New comment | requester + assignee | requester + assignee |
| Status change | requester | — |
| Closed | requester | manager mailbox + assignee + requester |
| Due date approaching / overdue | assignee (or manager group) | assignee (or manager group) |
| Settings saved | manager group | — |

The **manager mailbox** (an email address, not necessarily a Nextcloud
account) is configured from the admin settings.

## Security and privacy

- **Excel export protected against formula injection**: any value starting
  with a character Excel/LibreOffice would interpret as a formula (`=`,
  `+`, `-`, `@`...) is neutralized before export.
- **Secure attachment download**: files that can't be previewed are always
  served as a download (never interpreted by the browser), and accented
  filenames are correctly encoded (RFC 5987/6266).
- **GDPR**:
  - *Right to data portability*: each user can export their own Tickets
    data (tickets, comments, attachments, activity) from
    Settings → Personal → Privacy → Download my data (requires the
    official [user_migration](https://apps.nextcloud.com/apps/user_migration)
    app).
  - *Right to erasure*: when an account is deleted, that user's tickets and
    their associated data are deleted; their actions on other users'
    tickets (comments, attachments, assignment) are removed without
    touching the rest of the ticket.
- **Full reset** (admin settings, admin-only, with a confirmation word):
  consistently deletes tickets, comments, activity, read markers, and
  attachments (files and metadata).

## Installation

1. Copy this folder into `nextcloud/apps/tickets` (or `custom_apps/tickets`
   depending on the instance's configuration).
2. Install dependencies and build the frontend:
   ```bash
   cd apps/tickets
   npm install
   npm run build
   ```
3. Enable the app:
   ```bash
   php occ app:enable tickets
   ```
   The required tables (`oc_tickets`, `oc_tickets_comments`,
   `oc_tickets_reads`, `oc_tickets_attachments`, `oc_tickets_activity`) are
   created automatically on activation.

## Configuration

Everything is set from **Admin settings → Tickets**:

- requester and manager groups;
- ticket categories (French/English labels, JSON import/export);
- manager group's email mailbox;
- attachment storage account, allowed extensions, and maximum size;
- enabling due dates;
- "Location" field label.

## Internationalization

The interface is available in French and English, automatically detected
from the logged-in Nextcloud account's language. To contribute a new
language, see `l10n/` (a server-side `.json` file and a client-side `.js`
file are needed per language).

## Development

- **Structure**: `lib/Controller` (REST API), `lib/Db` (entities and
  mappers), `lib/Service` (business logic), `lib/Migration` (SQL schema),
  `src/` (Vue.js interface), `l10n/` (translations).
- **Continuous build**: `npm run watch` from the app's folder.
- **Tests**: PHPUnit suite in `tests/Unit/`, to be run from a full
  Nextcloud installation (`composer test:unit`). See `tests/bootstrap.php`
  for details.
- **Versioning**: the version in `appinfo/info.xml` must be bumped on every
  shipped change — Nextcloud uses it to decide whether migrations need to
  be replayed.

More detailed development notes (pitfalls encountered, Docker
deployment...) are available in [`DEVELOPMENT.md`](DEVELOPMENT.md)
([French version](DEVELOPMENT.fr.md)).

## Roadmap ideas

- CSV/PDF export for tracking reports

## License

Distributed under the [AGPL-3.0-or-later](LICENSE) license.
