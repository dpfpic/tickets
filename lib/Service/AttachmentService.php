<?php

declare(strict_types=1);

namespace OCA\Tickets\Service;

use OCA\Tickets\Db\Attachment;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\Ticket;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Stocke les pièces jointes des tickets dans les Fichiers d'un compte
 * Nextcloud unique, configuré par l'admin (ConfigService::getStorageAccountUid()) —
 * pas dans les Fichiers de chaque demandeur : ce sont des pièces destinées à
 * être vues et gérées par tout le conseil syndical, pas des documents
 * personnels. Un dossier par ticket : Tickets/<numéro-ticket>/<nom-fichier>.
 *
 * Les dossiers de ticket ne sont pas partagés automatiquement avec le(s)
 * groupe(s) gestionnaire(s) : ils restent uniquement accessibles depuis le
 * ticket lui-même (upload, liste, téléchargement des pièces jointes via
 * l'API), pas directement dans l'app Fichiers du conseil syndical.
 */
class AttachmentService {
    private IRootFolder $rootFolder;
    private ConfigService $configService;
    private AttachmentMapper $attachmentMapper;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;

    public function __construct(
        IRootFolder $rootFolder,
        ConfigService $configService,
        AttachmentMapper $attachmentMapper,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->configService = $configService;
        $this->attachmentMapper = $attachmentMapper;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
    }

    /** Un compte de stockage a-t-il été choisi par l'admin ? */
    public function isConfigured(): bool {
        return $this->configService->getStorageAccountUid() !== '';
    }

    /**
     * Enregistre le fichier reçu dans Tickets/<numéro-ticket>/ sur le compte
     * de stockage, puis crée la ligne de métadonnées correspondante.
     *
     * @param string $tmpPath Chemin local du fichier uploadé (ex. $_FILES[...]['tmp_name'])
     * @throws \RuntimeException si aucun compte de stockage n'est configuré, ou en cas
     *         d'échec d'écriture (compte introuvable, quota dépassé...)
     * @throws \InvalidArgumentException si l'extension du fichier n'est pas autorisée
     * @throws \LengthException si le fichier dépasse la taille max configurée (ConfigService::getMaxAttachmentSizeMb)
     */
    public function addAttachment(Ticket $ticket, string $uploaderUid, string $tmpPath, string $originalName, ?string $mimetype): Attachment {
        $name = $this->sanitizeFileName($originalName);
        if (!$this->isAllowedExtension($name)) {
            throw new \InvalidArgumentException(
                'File type not allowed: ' . $name . '. Allowed extensions: ' . implode(', ', $this->configService->getAllowedExtensions())
            );
        }

        $maxBytes = $this->configService->getMaxAttachmentSizeMb() * 1024 * 1024;
        $actualSize = @filesize($tmpPath);
        if ($actualSize !== false && $actualSize > $maxBytes) {
            throw new \LengthException(
                'File too large: ' . $name . '. Maximum size: ' . $this->configService->getMaxAttachmentSizeMb() . ' MB'
            );
        }

        $folder = $this->getTicketFolder($ticket, true);

        $name = $this->uniqueFileName($folder, $name);

        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read uploaded file');
        }

        try {
            /** @var File $file */
            $file = $folder->newFile($name, $handle);
        } catch (NotPermittedException $e) {
            throw new \RuntimeException('Could not store attachment: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $attachment = new Attachment();
        $attachment->setTicketId($ticket->getId());
        $attachment->setFileName($file->getName());
        $attachment->setMimetype($mimetype !== null && $mimetype !== '' ? $mimetype : $file->getMimeType());
        $attachment->setSize($file->getSize());
        $attachment->setUploadedBy($uploaderUid);
        $attachment->setCreatedAt(time());

        return $this->attachmentMapper->insert($attachment);
    }

    /**
     * URL ouvrant directement, dans l'app Fichiers, le dossier de pièces
     * jointes du ticket (ex. bouton « Ouvrir le dossier » du tableau des
     * tickets, côté gestionnaire). Le dossier doit déjà exister (donc au
     * moins une pièce jointe déposée). Le dossier n'étant pas partagé
     * automatiquement, ce lien n'est réellement ouvrable que par le
     * propriétaire du compte de stockage des pièces jointes ; un membre du
     * conseil syndical sans accès à ce dossier obtiendra une erreur
     * d'autorisation côté Fichiers.
     *
     * @throws NotFoundException si le dossier du ticket n'existe pas (aucune pièce jointe)
     * @throws \RuntimeException si aucun compte de stockage n'est configuré
     */
    public function getFolderUrl(Ticket $ticket): string {
        $folder = $this->getTicketFolder($ticket, false);

        // Lien "interne" standard de Nextcloud (celui du menu « Copier le lien
        // interne » de l'app Fichiers) : redirige vers l'emplacement réel du
        // dossier pour l'utilisateur courant, qu'il s'agisse du propriétaire
        // ou d'un membre d'un partage — pas besoin de connaître le chemin tel
        // qu'il apparaît côté destinataire du partage.
        return $this->urlGenerator->getAbsoluteURL('/f/' . $folder->getId());
    }

    /**
     * @throws NotFoundException si le fichier n'existe plus sur le disque (ex. supprimé
     *         directement depuis les Fichiers du compte de stockage)
     * @throws \RuntimeException si aucun compte de stockage n'est configuré
     */
    public function getFile(Attachment $attachment, Ticket $ticket): File {
        $folder = $this->getTicketFolder($ticket, false);
        $node = $folder->get($attachment->getFileName());
        if (!($node instanceof File)) {
            throw new NotFoundException($attachment->getFileName());
        }
        return $node;
    }

    /** Supprime le fichier (si présent) et la ligne de métadonnées d'une pièce jointe. */
    public function deleteAttachment(Attachment $attachment, Ticket $ticket): void {
        try {
            $this->getFile($attachment, $ticket)->delete();
        } catch (NotFoundException|NotPermittedException $e) {
            // Déjà absent du disque (ou compte de stockage non accessible) : on
            // continue quand même pour ne pas laisser une ligne orpheline en base.
        }
        $this->attachmentMapper->delete($attachment);
    }

    /**
     * Supprime tout le dossier de pièces jointes d'un ticket (et leurs métadonnées),
     * appelée quand le ticket lui-même est supprimé.
     */
    public function deleteAllForTicket(Ticket $ticket): void {
        if ($this->isConfigured()) {
            try {
                $this->getTicketFolder($ticket, false)->delete();
            } catch (NotFoundException|NotPermittedException $e) {
                // Pas de dossier (aucune pièce jointe n'a jamais été ajoutée) : rien à faire.
            }
        }
        $this->attachmentMapper->deleteByTicket($ticket->getId());
    }

    /**
     * Vide le dossier Tickets/ (tous les sous-dossiers de ticket, quel que soit leur
     * sous-dossier de statut) sur le compte de stockage configuré, mais conserve le
     * dossier Tickets/ lui-même : il a pu être créé (ou personnalisé — partages,
     * droits...) directement par l'utilisateur du compte de stockage, pas uniquement
     * par l'appli, donc seul son contenu (les pièces jointes) doit être supprimé.
     * Appelée par SettingsController::reset() lors d'une remise à zéro complète de la
     * base : sans ça, les fichiers déposés restaient orphelins sur le compte de
     * stockage (et leurs lignes de métadonnées, supprimées par
     * AttachmentMapper::deleteAll(), ne permettaient alors même plus de les retrouver).
     * Sans effet si aucun compte n'est configuré ou si le dossier Tickets/ n'existe pas
     * encore (aucune pièce jointe n'a jamais été déposée).
     */
    public function deleteAllTicketFolders(): void {
        if (!$this->isConfigured()) {
            return;
        }
        try {
            $ticketsFolder = $this->getTicketsRootFolder(false);
        } catch (NotFoundException|NotPermittedException $e) {
            // Pas de dossier Tickets/ : rien à supprimer.
            return;
        }
        foreach ($ticketsFolder->getDirectoryListing() as $node) {
            try {
                $node->delete();
            } catch (NotPermittedException $e) {
                // Nœud non supprimable (droits...) : on continue avec les autres plutôt
                // que de faire échouer tout le reset pour un seul dossier récalcitrant.
            }
        }
    }

    /**
     * Appelée quand l'admin change le compte de stockage des pièces jointes
     * (SettingsController::saveConfig) : migre le dossier Tickets/ existant de
     * l'ancien compte vers le nouveau, pour qu'aucun dossier de ticket ne reste
     * orphelin sur l'ancien compte. Sans effet si l'un des deux comptes est vide,
     * s'ils sont identiques, ou si l'ancien compte n'a pas de dossier Tickets/
     * (rien à migrer).
     *
     * Une copie (pas un déplacement direct) est utilisée pour chaque nœud, seule
     * approche fiable entre deux comptes Nextcloud distincts (donc deux racines de
     * stockage distinctes) ; l'original n'est supprimé qu'une fois la copie faite.
     *
     * @throws \RuntimeException si le nouveau compte n'est pas accessible, ou si la
     *         migration échoue en cours de route (le dossier de l'ancien compte est
     *         alors laissé tel quel plutôt que d'être supprimé à moitié migré).
     */
    public function migrateStorageAccount(string $oldUid, string $newUid): void {
        if ($oldUid === '' || $newUid === '' || $oldUid === $newUid) {
            return;
        }

        try {
            $oldUserFolder = $this->rootFolder->getUserFolder($oldUid);
        } catch (\Throwable $e) {
            // Ancien compte introuvable/inaccessible : rien à migrer depuis là.
            return;
        }
        if (!$oldUserFolder->nodeExists('Tickets')) {
            return;
        }
        $oldTickets = $oldUserFolder->get('Tickets');
        if (!($oldTickets instanceof Folder)) {
            return;
        }
        // Dossier Tickets/ vide (plus aucun ticket avec pièce jointe) : rien à migrer,
        // pas la peine de créer un dossier vide sur le nouveau compte.
        if (count($oldTickets->getDirectoryListing()) === 0) {
            return;
        }

        try {
            $newUserFolder = $this->rootFolder->getUserFolder($newUid);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Attachment storage account "' . $newUid . '" is not accessible: ' . $e->getMessage(), 0, $e);
        }

        if ($newUserFolder->nodeExists('Tickets')) {
            // Le nouveau compte a déjà un dossier Tickets/ (ex. précédemment utilisé,
            // puis remplacé, puis re-choisi) : on fusionne le contenu de l'ancien
            // dedans plutôt que d'échouer ou d'écraser.
            $newTickets = $newUserFolder->get('Tickets');
            if (!($newTickets instanceof Folder)) {
                throw new \RuntimeException('Expected a folder at Tickets on the new storage account');
            }
            $this->mergeFolderInto($oldTickets, $newTickets);
        } else {
            $oldTickets->copy($newUserFolder->getPath() . '/Tickets');
        }

        $oldTickets->delete();
    }

    /**
     * Copie récursivement le contenu de $source dans $destination : les sous-dossiers
     * de même nom sont fusionnés (pas remplacés), un conflit de nom entre un fichier et
     * un dossier est résolu en renommant la copie plutôt qu'en écrasant quoi que ce soit.
     */
    private function mergeFolderInto(Folder $source, Folder $destination): void {
        foreach ($source->getDirectoryListing() as $node) {
            $name = $node->getName();
            if ($node instanceof Folder && $destination->nodeExists($name)) {
                $existing = $destination->get($name);
                if ($existing instanceof Folder) {
                    $this->mergeFolderInto($node, $existing);
                    continue;
                }
                $name = $this->uniqueFileName($destination, $name);
            } elseif ($destination->nodeExists($name)) {
                $name = $this->uniqueFileName($destination, $name);
            }
            $node->copy($destination->getPath() . '/' . $name);
        }
    }

    /**
     * Sous-dossier de rangement selon le statut du ticket, pour distinguer visuellement
     * dans Fichiers les tickets résolus/fermés des tickets encore actifs :
     *   - actif (nouveau, en cours) -> Tickets/<numéro>/
     *   - résolu -> Tickets/Résolus/<numéro>/
     *   - fermé  -> Tickets/Fermés/<numéro>/
     * Un ticket supprimé n'a pas de sous-dossier dédié : son dossier est simplement
     * supprimé (voir deleteAllForTicket), comme avant cette fonctionnalité.
     */
    private function statusSubfolder(string $status): ?string {
        return match ($status) {
            'resolved' => 'Résolus',
            'closed' => 'Fermés',
            default => null,
        };
    }

    /**
     * Range immédiatement le dossier de pièces jointes du ticket dans le bon sous-dossier
     * de statut (voir statusSubfolder()), à appeler juste après un changement de statut
     * plutôt que d'attendre la prochaine ouverture/dépôt de pièce jointe. Sans effet si
     * aucun compte de stockage n'est configuré ou si le ticket n'a pas encore de dossier
     * (aucune pièce jointe déposée) : dans ce cas le rangement se fera naturellement à la
     * création du dossier, au premier dépôt.
     */
    public function relocateFolderForStatus(Ticket $ticket): void {
        if (!$this->isConfigured()) {
            return;
        }
        try {
            $this->getTicketFolder($ticket, false);
        } catch (NotFoundException|NotPermittedException|\RuntimeException $e) {
            // Pas de dossier à ranger, ou compte de stockage inaccessible : ne bloque pas
            // le changement de statut du ticket pour autant.
        }
    }

    /**
     * @throws \RuntimeException si aucun compte de stockage n'est configuré, ou si le
     *         compte configuré n'existe pas / n'est pas accessible
     */
    private function getTicketFolder(Ticket $ticket, bool $create): Folder {
        $ticketsFolder = $this->getTicketsRootFolder($create);
        $name = $ticket->getTicketNumber();
        $targetSubfolder = $this->statusSubfolder($ticket->getStatus() ?: 'new');

        // Emplacement attendu compte tenu du statut actuel : on le cherche en premier.
        $target = $this->findTicketFolderIn($ticketsFolder, $targetSubfolder, $name);
        if ($target !== null) {
            return $target;
        }

        // Pas trouvé au bon endroit : on cherche dans les autres emplacements possibles
        // (dossier créé avant l'ajout de ce rangement par statut, ou statut changé entre
        // deux ouvertures) et on déplace le dossier vers son emplacement attendu.
        foreach ([null, 'Résolus', 'Fermés'] as $otherSubfolder) {
            if ($otherSubfolder === $targetSubfolder) {
                continue;
            }
            $found = $this->findTicketFolderIn($ticketsFolder, $otherSubfolder, $name);
            if ($found !== null) {
                return $this->moveTicketFolder($found, $ticketsFolder, $targetSubfolder, $name);
            }
        }

        if (!$create) {
            throw new NotFoundException($name);
        }

        $destinationParent = $targetSubfolder !== null
            ? $this->getOrCreateSubfolder($ticketsFolder, $targetSubfolder)
            : $ticketsFolder;

        // Pas de partage automatique avec le(s) groupe(s) gestionnaire(s) : le dossier
        // reste accessible uniquement depuis le ticket lui-même (voir le commentaire de
        // classe ci-dessus).
        return $destinationParent->newFolder($name);
    }

    /** Cherche le dossier du ticket dans Tickets/<$subfolder>/ (ou Tickets/ si $subfolder est null). Ne le crée jamais. */
    private function findTicketFolderIn(Folder $ticketsFolder, ?string $subfolder, string $name): ?Folder {
        $parent = $ticketsFolder;
        if ($subfolder !== null) {
            if (!$ticketsFolder->nodeExists($subfolder)) {
                return null;
            }
            $node = $ticketsFolder->get($subfolder);
            if (!($node instanceof Folder)) {
                return null;
            }
            $parent = $node;
        }

        if (!$parent->nodeExists($name)) {
            return null;
        }
        $node = $parent->get($name);
        if (!($node instanceof Folder)) {
            throw new \RuntimeException('Expected a folder at ' . $node->getPath());
        }
        return $node;
    }

    /** Déplace le dossier trouvé vers son sous-dossier de statut attendu (le crée si besoin). */
    private function moveTicketFolder(Folder $folder, Folder $ticketsFolder, ?string $targetSubfolder, string $name): Folder {
        $destinationParent = $targetSubfolder !== null
            ? $this->getOrCreateSubfolder($ticketsFolder, $targetSubfolder)
            : $ticketsFolder;

        try {
            $folder->move($destinationParent->getPath() . '/' . $name);
        } catch (\Throwable $e) {
            // Échec du déplacement (ex. conflit de nom improbable) : on continue avec le
            // dossier là où il est plutôt que de faire échouer l'opération en cours.
            return $folder;
        }

        return $destinationParent->get($name);
    }

    /** Récupère (ou crée) le sous-dossier Tickets/<$name>/ utilisé pour ranger par statut. */
    private function getOrCreateSubfolder(Folder $ticketsFolder, string $name): Folder {
        if ($ticketsFolder->nodeExists($name)) {
            $node = $ticketsFolder->get($name);
            if ($node instanceof Folder) {
                return $node;
            }
            throw new \RuntimeException('Expected a folder at Tickets/' . $name);
        }
        return $ticketsFolder->newFolder($name);
    }

    private function getTicketsRootFolder(bool $create): Folder {
        $uid = $this->configService->getStorageAccountUid();
        if ($uid === '') {
            throw new \RuntimeException('No attachment storage account configured');
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Attachment storage account "' . $uid . '" is not accessible: ' . $e->getMessage(), 0, $e);
        }

        if ($userFolder->nodeExists('Tickets')) {
            $node = $userFolder->get('Tickets');
            if (!($node instanceof Folder)) {
                throw new \RuntimeException('Expected a folder at Tickets');
            }
            return $node;
        }

        if (!$create) {
            throw new NotFoundException('Tickets');
        }

        return $userFolder->newFolder('Tickets');
    }

    /** Le nom de fichier a-t-il une extension présente dans ConfigService::getAllowedExtensions() ? */
    private function isAllowedExtension(string $name): bool {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return false;
        }
        $ext = strtolower(substr($name, $dot + 1));
        return in_array($ext, $this->configService->getAllowedExtensions(), true);
    }

    /**
     * Retire les séparateurs de chemin d'un nom de fichier reçu du client, par
     * sécurité, et décode les entités HTML qu'il peut contenir : certains
     * outils de capture d'écran / navigateurs livrent des noms déjà encodés
     * (ex. "Copie d&#39;écran...png" au lieu de "Copie d'écran...png"), ce
     * qui s'affichait tel quel, entités comprises, partout où le nom est
     * ensuite utilisé (liste des pièces jointes, journal d'activité...).
     */
    private function sanitizeFileName(string $name): string {
        $name = html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = str_replace(['/', '\\'], '_', $name);
        return $name !== '' ? $name : 'file';
    }

    /**
     * Évite d'écraser une pièce jointe existante du même nom : suffixe
     * "(2)", "(3)"... avant l'extension, comme le fait l'app Fichiers.
     */
    private function uniqueFileName(Folder $folder, string $name): string {
        if (!$folder->nodeExists($name)) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $base = $dot !== false ? substr($name, 0, $dot) : $name;
        $ext = $dot !== false ? substr($name, $dot) : '';

        $suffix = 2;
        do {
            $candidate = $base . ' (' . $suffix . ')' . $ext;
            $suffix++;
        } while ($folder->nodeExists($candidate));

        return $candidate;
    }
}
