# 🎫 Tickets — gestion de demandes pour Nextcloud

*[English version](README.md)*

**Tickets** est une appli [Nextcloud](https://nextcloud.com) simple de
gestion de demandes/tickets : les utilisateurs soumettent des requêtes, un
groupe gestionnaire les suit, priorise et y répond depuis un tableau dédié.

L'appli n'est liée à aucun cas d'usage particulier : elle convient aussi
bien à un conseil syndical de copropriété (demandes d'intervention) qu'à un
support interne d'entreprise, un service RH, ou toute organisation qui a
besoin d'un point d'entrée simple pour centraliser des demandes. Les groupes
autorisés à déposer ou à gérer les demandes, ainsi que les catégories de
tickets, sont entièrement configurables depuis les réglages admin.

- **Auteur** : DPFPIC
- **Licence** : [AGPL-3.0-or-later](LICENSE)
- **Compatibilité** : Nextcloud 27 à 34

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Rôles et permissions](#rôles-et-permissions)
- [Notifications](#notifications)
- [Sécurité et confidentialité](#sécurité-et-confidentialité)
- [Installation](#installation)
- [Configuration](#configuration)
- [Internationalisation](#internationalisation)
- [Développement](#développement)
- [Licence](#licence)

## Fonctionnalités

- **Création de demande** en un clic, avec titre, description, catégorie,
  priorité, et détection des doublons potentiels (titre proche d'un ticket
  encore ouvert du même demandeur, avec possibilité de confirmer malgré
  tout).
- **Tableau de suivi** filtrable (statut, priorité, catégorie, assigné) et
  triable par colonne, avec des compteurs cliquables par statut
  (Tous / Nouveau / En cours / Résolu / Fermé).
- **Priorités et statuts lisibles sans dépendre de la couleur** : chaque
  niveau de priorité porte aussi une icône de forme, pour rester accessible.
- **Fiche de détail** par ticket : description, fil de commentaires,
  journal d'activité horodaté (changements de statut, priorité, assignation,
  échéance, pièces jointes), et formulaire de réponse.
- **Pièces jointes** par sélecteur de fichiers ou glisser-déposer, avec :
  - liste d'extensions autorisées et taille maximale configurables par
    l'admin ;
  - stockage dans un compte Nextcloud dédié, rangé automatiquement par
    ticket (et par statut : actifs / résolus / fermés) ;
  - compatibilité automatique avec un antivirus déjà installé sur
    l'instance ([Files_Antivirus](https://apps.nextcloud.com/apps/files_antivirus)).
- **Échéances** ("à traiter avant le") avec relances automatiques
  configurables à l'approche ou au dépassement de la date.
- **Export Excel** : export ponctuel de la vue filtrée du tableau pour le
  groupe gestionnaire, et export complet (avec commentaires) réservé à
  l'admin, pour archivage/reporting.
- **Notifications** in-app et par email aux étapes clés du cycle de vie
  d'un ticket (voir [Notifications](#notifications)).
- **Repère "non lu"** par utilisateur : badge et mise en gras des tickets
  ayant un nouveau message depuis la dernière consultation.
- **Vue mobile en cartes** : le tableau se réorganise automatiquement sous
  640px de large.
- **Catégories personnalisables** (libellés français/anglais), export/
  import JSON pour les dupliquer d'une instance à l'autre.
- **Conformité RGPD** : export des données personnelles et suppression en
  cascade à la fermeture d'un compte, via les mécanismes standards de
  Nextcloud (voir [Sécurité et confidentialité](#sécurité-et-confidentialité)).
- **Interface bilingue** français/anglais, détectée automatiquement selon
  la langue du compte Nextcloud.

## Rôles et permissions

| Rôle | Peut faire |
|---|---|
| **Demandeur** (tout utilisateur connecté, ou membre d'un groupe "demandeur" si configuré) | Créer une demande, voir et modifier ses propres tickets tant qu'ils sont au statut "Nouveau", commenter, gérer ses propres pièces jointes |
| **Groupe gestionnaire** | Voir tous les tickets, changer statut/priorité/assignation/échéance, corriger le nom/localisation du demandeur, supprimer un ticket, exporter en Excel, configurer l'appli |

Les groupes "gestionnaires" et "demandeurs" se choisissent depuis
**Réglages admin → Tickets**, parmi les groupes Nextcloud existants.

## Notifications

Chaque évènement notable déclenche une notification in-app (cloche) et,
pour certains évènements, un email via le serveur SMTP déjà configuré sur
l'instance :

| Évènement | Cloche | Email |
|---|---|---|
| Création d'un ticket | groupe gestionnaire | boîte gestionnaire + initiateur |
| Prise en charge (assignation) | — | boîte gestionnaire |
| Nouveau commentaire | initiateur + assigné | initiateur + assigné |
| Changement de statut | initiateur | — |
| Clôture | initiateur | boîte gestionnaire + assigné + initiateur |
| Échéance proche / dépassée | assigné (ou groupe gestionnaire) | assigné (ou groupe gestionnaire) |
| Réglages enregistrés | groupe gestionnaire | — |

La **boîte gestionnaire** (une adresse email, pas nécessairement un compte
Nextcloud) se configure depuis les réglages admin.

## Sécurité et confidentialité

- **Export Excel protégé contre l'injection de formule** : toute valeur
  commençant par un caractère interprétable comme une formule par Excel/
  LibreOffice (`=`, `+`, `-`, `@`...) est neutralisée avant export.
- **Téléchargement de pièce jointe sécurisé** : les fichiers non prévisualisables
  sont toujours servis en téléchargement (jamais interprétés par le
  navigateur), et les noms de fichiers accentués sont correctement encodés
  (RFC 5987/6266).
- **RGPD** :
  - *Droit à la portabilité* : chaque utilisateur peut exporter ses propres
    données Tickets (tickets, commentaires, pièces jointes, activité) depuis
    Réglages → Personnel → Vie privée → Télécharger mes données (nécessite
    l'app officielle [user_migration](https://apps.nextcloud.com/apps/user_migration)).
  - *Droit à l'effacement* : à la suppression d'un compte, ses tickets et
    leurs données associées sont supprimés ; ses interventions sur les
    tickets d'un tiers (commentaires, pièces jointes, assignation) sont
    retirées sans toucher au reste du ticket.
- **Remise à zéro complète** (réglages admin, réservée à l'admin, avec mot
  de confirmation) : supprime tickets, commentaires, activité, marqueurs de
  lecture et pièces jointes (fichiers et métadonnées) de façon cohérente.

## Installation

1. Copier ce dossier dans `nextcloud/apps/tickets` (ou `custom_apps/tickets`
   selon la configuration de l'instance).
2. Installer les dépendances et builder le frontend :
   ```bash
   cd apps/tickets
   npm install
   npm run build
   ```
3. Activer l'appli :
   ```bash
   php occ app:enable tickets
   ```
   Les tables nécessaires (`oc_tickets`, `oc_tickets_comments`,
   `oc_tickets_reads`, `oc_tickets_attachments`, `oc_tickets_activity`) sont
   créées automatiquement à l'activation.

## Configuration

Tout se règle depuis **Réglages admin → Tickets** :

- groupes demandeurs et gestionnaires ;
- catégories de tickets (libellés français/anglais, import/export JSON) ;
- boîte email du groupe gestionnaire ;
- compte de stockage des pièces jointes, extensions autorisées et taille
  maximale ;
- activation des échéances ;
- libellé du champ "Localisation".

## Internationalisation

L'interface est disponible en français et en anglais, détectée
automatiquement selon la langue du compte Nextcloud connecté. Pour
contribuer une nouvelle langue, voir `l10n/` (un fichier `.json` côté
serveur et un fichier `.js` côté client sont nécessaires par langue).

## Développement

- **Structure** : `lib/Controller` (API REST), `lib/Db` (entités et
  mappers), `lib/Service` (logique métier), `lib/Migration` (schéma SQL),
  `src/` (interface Vue.js), `l10n/` (traductions).
- **Build en continu** : `npm run watch` depuis le dossier de l'appli.
- **Tests** : suite PHPUnit dans `tests/Unit/`, à lancer depuis une
  installation Nextcloud complète (`composer test:unit`). Voir
  `tests/bootstrap.php` pour les détails.
- **Versioning** : la version dans `appinfo/info.xml` doit être incrémentée
  à chaque modification livrée — Nextcloud s'en sert pour décider si les
  migrations doivent être rejouées.

Des notes de développement plus détaillées (pièges rencontrés, déploiement
Docker...) sont disponibles dans [`DEVELOPMENT.fr.md`](DEVELOPMENT.fr.md)
([version anglaise](DEVELOPMENT.md)).

## Pistes d'évolution

- Export CSV/PDF pour les rapports de suivi

## Licence

Distribué sous licence [AGPL-3.0-or-later](LICENSE).
