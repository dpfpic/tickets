<?php

declare(strict_types=1);

namespace OCA\Tickets\Service;

use OCA\Tickets\AppInfo\Application;
use OCP\IConfig;

/**
 * Centralise la configuration de l'app stockée en appconfig :
 * - les groupes "gestionnaires" (gèrent les tickets)
 * - les groupes "demandeurs" (ont le droit de déposer des tickets ; liste vide = tous les utilisateurs)
 * - la liste des catégories de tickets
 *
 * Compatibilité ascendante : avant la 0.4.0, un seul groupe gestionnaire et
 * un seul groupe demandeur pouvaient être configurés (clés `board_group` /
 * `requester_group`, valeur simple). Ces anciens réglages restent lus tant
 * qu'aucune valeur n'a été enregistrée sous les nouvelles clés `board_groups` /
 * `requester_groups` (tableaux JSON) ; le premier enregistrement via la
 * nouvelle UI d'administration bascule définitivement sur le nouveau format.
 * Les catégories, elles, n'existaient pas avant la 0.4.0 : en l'absence de
 * réglage, on retombe sur la liste historique codée en dur dans le code
 * précédent (voir DEFAULT_CATEGORIES) afin que les tickets déjà créés
 * conservent une catégorie valide et traduite.
 */
class ConfigService {
    private const KEY_BOARD_GROUP = 'board_group'; // legacy (< 0.4.0)
    private const KEY_REQUESTER_GROUP = 'requester_group'; // legacy (< 0.4.0)
    private const KEY_BOARD_GROUPS = 'board_groups';
    private const KEY_REQUESTER_GROUPS = 'requester_groups';
    private const KEY_CATEGORIES = 'categories';
    private const KEY_MANAGER_EMAIL = 'manager_email';
    private const KEY_STORAGE_ACCOUNT_UID = 'storage_account_uid';
    private const KEY_OPEN_IN_NEW_TAB = 'open_in_new_tab';
    private const KEY_LOCATION_LABEL_FR = 'location_label_fr';
    private const KEY_LOCATION_LABEL_EN = 'location_label_en';
    private const KEY_DUE_DATE_ENABLED = 'due_date_enabled';
    private const KEY_ALLOWED_EXTENSIONS = 'allowed_extensions';
    private const KEY_MAX_ATTACHMENT_SIZE_MB = 'max_attachment_size_mb';

    /** Taille max par défaut d'une pièce jointe, tant que l'admin n'a rien configuré. */
    private const DEFAULT_MAX_ATTACHMENT_SIZE_MB = 20;

    /**
     * Extensions historiquement codées en dur dans AttachmentService (< 0.11.0),
     * utilisées tant que l'admin n'a rien enregistré sous KEY_ALLOWED_EXTENSIONS.
     */
    private const DEFAULT_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'docx', 'pdf', 'txt'];

    /**
     * Catégories historiques (0.3.x). Depuis la 0.4.0, chaque catégorie porte
     * un libellé français et un libellé anglais explicites (label_fr /
     * label_en) plutôt qu'une clé de traduction i18n unique.
     */
    private const DEFAULT_CATEGORIES = [
        ['value' => 'plumbing', 'label_fr' => 'Plomberie', 'label_en' => 'Plumbing'],
        ['value' => 'elevator', 'label_fr' => 'Ascenseur', 'label_en' => 'Elevator'],
        ['value' => 'common_areas', 'label_fr' => 'Parties communes', 'label_en' => 'Common areas'],
        ['value' => 'nuisance', 'label_fr' => 'Nuisance', 'label_en' => 'Nuisance'],
        ['value' => 'other', 'label_fr' => 'Autre', 'label_en' => 'Other'],
    ];

    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    /**
     * @return string[] Groupes dont les membres gèrent les tickets. Toujours au moins un élément.
     */
    public function getBoardGroups(): array {
        $groups = $this->decodeGroupList(self::KEY_BOARD_GROUPS);
        if ($groups !== null && count($groups) > 0) {
            return $groups;
        }

        // Repli sur l'ancien réglage à valeur unique
        $legacy = $this->config->getAppValue(Application::APP_ID, self::KEY_BOARD_GROUP, Application::BOARD_GROUP);
        return [$legacy !== '' ? $legacy : Application::BOARD_GROUP];
    }

    /**
     * @param string[] $gids
     */
    public function setBoardGroups(array $gids): void {
        $gids = array_values(array_unique(array_filter(array_map('strval', $gids), static fn (string $g) => $g !== '')));
        $this->config->setAppValue(Application::APP_ID, self::KEY_BOARD_GROUPS, json_encode($gids));
    }

    /**
     * @return string[] Groupes autorisés à déposer des demandes. Tableau vide = tous les
     * utilisateurs connectés (comportement historique).
     */
    public function getRequesterGroups(): array {
        $groups = $this->decodeGroupList(self::KEY_REQUESTER_GROUPS);
        if ($groups !== null) {
            return $groups;
        }

        // Repli sur l'ancien réglage à valeur unique (chaîne vide = tous les utilisateurs)
        $legacy = $this->config->getAppValue(Application::APP_ID, self::KEY_REQUESTER_GROUP, '');
        return $legacy !== '' ? [$legacy] : [];
    }

    /**
     * @param string[] $gids Tableau vide = tous les utilisateurs connectés
     */
    public function setRequesterGroups(array $gids): void {
        $gids = array_values(array_unique(array_filter(array_map('strval', $gids), static fn (string $g) => $g !== '')));
        $this->config->setAppValue(Application::APP_ID, self::KEY_REQUESTER_GROUPS, json_encode($gids));
    }

    /**
     * @return array<int, array{value: string, label_fr: string, label_en: string}>
     */
    public function getCategories(): array {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_CATEGORIES, '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && count($decoded) > 0) {
                return array_map([$this, 'normalizeStoredCategory'], $decoded);
            }
        }
        return self::DEFAULT_CATEGORIES;
    }

    /**
     * Convertit une catégorie éventuellement enregistrée dans l'ancien format
     * (< 0.4.0, un seul champ `label`) vers le format actuel label_fr/label_en,
     * pour que les catégories créées avant la mise à jour restent visibles et
     * pré-remplies dans le formulaire d'administration au lieu d'apparaître vides.
     *
     * @param array{value?: string, label?: string, label_fr?: string, label_en?: string} $category
     * @return array{value: string, label_fr: string, label_en: string}
     */
    private function normalizeStoredCategory(array $category): array {
        $labelFr = (string) ($category['label_fr'] ?? '');
        $labelEn = (string) ($category['label_en'] ?? '');
        if ($labelFr === '' && $labelEn === '') {
            // Ancien format : un seul libellé, réutilisé pour les deux langues
            $legacyLabel = (string) ($category['label'] ?? '');
            $labelFr = $legacyLabel;
            $labelEn = $legacyLabel;
        }
        return [
            'value' => (string) ($category['value'] ?? ''),
            'label_fr' => $labelFr,
            'label_en' => $labelEn,
        ];
    }

    /** @return string[] */
    public function getCategoryValues(): array {
        return array_column($this->getCategories(), 'value');
    }

    /**
     * @param array<int, array{value: string, label_fr: string, label_en: string}> $categories
     */
    public function setCategories(array $categories): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_CATEGORIES, json_encode(array_values($categories)));
    }

    /**
     * Adresse email d'une boîte gestionnaire (groupe ou personne), utilisée pour
     * l'envoi de notifications par email en plus de la cloche in-app. Chaîne vide =
     * aucune adresse configurée, donc aucun email envoyé à ce destinataire.
     */
    public function getManagerEmail(): string {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_MANAGER_EMAIL, '');
    }

    public function setManagerEmail(string $email): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_MANAGER_EMAIL, trim($email));
    }

    /**
     * UID du compte Nextcloud sur lequel sont stockées les pièces jointes des
     * tickets (dossier Tickets/<numéro-ticket>/ dans les Fichiers de ce
     * compte). Un compte unique et partagé plutôt que celui de chaque
     * demandeur : les pièces jointes doivent rester visibles/gérables par
     * tout le conseil syndical (gestionnaires), pas dispersées dans les
     * Fichiers personnels de chaque demandeur. Chaîne vide = pas encore
     * configuré par l'admin, les pièces jointes sont alors désactivées
     * (voir AttachmentService).
     */
    public function getStorageAccountUid(): string {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_STORAGE_ACCOUNT_UID, '');
    }

    public function setStorageAccountUid(string $uid): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_STORAGE_ACCOUNT_UID, trim($uid));
    }

    /**
     * Ouvrir les PDF (aperçu de pièce jointe) et les dossiers de pièces jointes
     * (bouton du tableau, côté gestionnaire) dans un nouvel onglet plutôt que
     * dans l'onglet courant. Activé par défaut (comportement historique).
     */
    public function getOpenInNewTab(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_OPEN_IN_NEW_TAB, '1') === '1';
    }

    public function setOpenInNewTab(bool $value): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_OPEN_IN_NEW_TAB, $value ? '1' : '0');
    }

    /**
     * Libellé personnalisé du champ "Localisation" (ex. "Appartement", "Bâtiment"),
     * pour l'adapter au vocabulaire de la copropriété. Un libellé par langue,
     * comme pour les catégories : chaîne vide = pas encore personnalisé pour
     * cette langue, on retombe alors sur l'autre langue puis sur le libellé
     * traduit par défaut ("Location" / "Localisation") côté affichage.
     */
    public function getLocationLabelFr(): string {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_LOCATION_LABEL_FR, '');
    }

    public function setLocationLabelFr(string $label): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_LOCATION_LABEL_FR, trim($label));
    }

    public function getLocationLabelEn(): string {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_LOCATION_LABEL_EN, '');
    }

    public function setLocationLabelEn(string $label): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_LOCATION_LABEL_EN, trim($label));
    }

    /**
     * Le champ "À traiter avant le" (due date) peut être entièrement désactivé
     * si la copropriété ne s'en sert pas. Activé par défaut (comportement historique).
     */
    public function getDueDateEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_DUE_DATE_ENABLED, '1') === '1';
    }

    public function setDueDateEnabled(bool $value): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_DUE_DATE_ENABLED, $value ? '1' : '0');
    }

    /**
     * @return string[] Extensions de fichier autorisées pour les pièces jointes (sans le
     * point, en minuscules). Toujours au moins un élément.
     */
    public function getAllowedExtensions(): array {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_ALLOWED_EXTENSIONS, '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && count($decoded) > 0) {
                return $decoded;
            }
        }
        return self::DEFAULT_ALLOWED_EXTENSIONS;
    }

    /**
     * @param string[] $extensions
     */
    public function setAllowedExtensions(array $extensions): void {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($ext) => strtolower(trim((string) $ext, ". \t\n\r\0\x0B")), $extensions),
            static fn (string $ext) => $ext !== ''
        )));
        $this->config->setAppValue(Application::APP_ID, self::KEY_ALLOWED_EXTENSIONS, json_encode($normalized));
    }

    /**
     * Taille maximale (en Mo) d'une pièce jointe, propre à l'appli — indépendante
     * (et censée rester inférieure ou égale) des limites globales du serveur PHP
     * (`upload_max_filesize` / `post_max_size`), qui produisent sinon un message
     * d'erreur générique et peu clair pour un admin qui découvre l'appli.
     */
    public function getMaxAttachmentSizeMb(): int {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_MAX_ATTACHMENT_SIZE_MB, '');
        $value = (int) $raw;
        return $value > 0 ? $value : self::DEFAULT_MAX_ATTACHMENT_SIZE_MB;
    }

    public function setMaxAttachmentSizeMb(int $mb): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_MAX_ATTACHMENT_SIZE_MB, (string) $mb);
    }

    /**
     * @return string[]|null null si aucune valeur n'a encore été enregistrée sous $key
     * (à distinguer d'un tableau vide, qui est une valeur valide pour les demandeurs)
     */
    private function decodeGroupList(string $key): ?array {
        $raw = $this->config->getAppValue(Application::APP_ID, $key, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
    }
}
