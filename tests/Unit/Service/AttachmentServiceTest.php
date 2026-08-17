<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Service;

use OCA\Tickets\Db\Attachment;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Service\AttachmentService;
use OCA\Tickets\Service\ConfigService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Tickets\Service\AttachmentService
 *
 * IRootFolder/Folder/File sont entièrement mockés (interfaces OCP\Files) :
 * on vérifie le comportement de AttachmentService (extensions autorisées,
 * rangement par statut, dé-duplication de nom, propagation des suppressions)
 * sans toucher à un vrai système de fichiers.
 */
class AttachmentServiceTest extends \Test\TestCase {
    /** @var IRootFolder&\PHPUnit\Framework\MockObject\MockObject */
    private $rootFolder;
    /** @var ConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private $configService;
    /** @var AttachmentMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $attachmentMapper;
    /** @var IURLGenerator&\PHPUnit\Framework\MockObject\MockObject */
    private $urlGenerator;
    private AttachmentService $service;

    /** @var string[] fichiers temporaires créés par un test, nettoyés dans tearDown() */
    private array $tmpFiles = [];

    protected function setUp(): void {
        parent::setUp();

        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->attachmentMapper = $this->createMock(AttachmentMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Par défaut, PHPUnit fait retourner un tableau vide à toute méthode
        // mockée dont le type de retour est array — sans ce stub, toute
        // pièce jointe est rejetée dès le contrôle d'extension, quel que
        // soit son nom, ce qui masque le comportement réel testé plus bas
        // (dédoublonnage, rangement par statut, compte de stockage manquant...).
        $this->configService->method('getAllowedExtensions')->willReturn(['jpg', 'jpeg', 'png', 'pdf', 'txt']);

        $this->service = new AttachmentService(
            $this->rootFolder,
            $this->configService,
            $this->attachmentMapper,
            $this->urlGenerator,
            $logger
        );
    }

    protected function tearDown(): void {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function makeTicket(int $id, string $status = 'new'): Ticket {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setCreatedAt(mktime(12, 0, 0, 6, 1, 2026));
        $ticket->setStatus($status);
        return $ticket;
    }

    /** Crée un fichier temporaire réel (addAttachment le lit via fopen()). */
    private function makeTmpUpload(string $content = 'contenu'): string {
        $path = tempnam(sys_get_temp_dir(), 'tickets-test-');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return Folder&\PHPUnit\Framework\MockObject\MockObject */
    private function folderMock() {
        return $this->createMock(Folder::class);
    }

    // ---- isConfigured() -----------------------------------------------

    public function testIsConfiguredFalseWhenNoStorageAccount(): void {
        $this->configService->method('getStorageAccountUid')->willReturn('');
        $this->assertFalse($this->service->isConfigured());
    }

    public function testIsConfiguredTrueWhenStorageAccountSet(): void {
        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->assertTrue($this->service->isConfigured());
    }

    // ---- addAttachment() : validations avant tout accès disque --------

    public function testAddAttachmentRejectsDisallowedExtension(): void {
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->addAttachment(
            $this->makeTicket(1),
            'alice',
            $this->makeTmpUpload(),
            'malware.exe',
            null
        );
    }

    public function testAddAttachmentThrowsWhenNoStorageAccountConfigured(): void {
        $this->configService->method('getStorageAccountUid')->willReturn('');

        $this->expectException(\RuntimeException::class);
        $this->service->addAttachment(
            $this->makeTicket(2),
            'alice',
            $this->makeTmpUpload(),
            'photo.jpg',
            'image/jpeg'
        );
    }

    // ---- addAttachment() : chemin complet, dossier "actif" (Tickets/) --

    public function testAddAttachmentStoresFileUnderTicketsRootForNewTicket(): void {
        $ticket = $this->makeTicket(7, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();
        $file = $this->createMock(File::class);

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

        $userFolder->method('nodeExists')->with('Tickets')->willReturn(false);
        $userFolder->expects($this->once())->method('newFolder')->with('Tickets')->willReturn($ticketsFolder);

        // Aucun dossier existant pour ce ticket, ni à l'emplacement attendu ni ailleurs.
        $ticketsFolder->method('nodeExists')->willReturnMap([
            [$ticketNumber, false],
            ['Résolus', false],
            ['Fermés', false],
        ]);
        $ticketsFolder->expects($this->once())->method('newFolder')->with($ticketNumber)->willReturn($ticketFolder);

        $ticketFolder->method('nodeExists')->with('photo.jpg')->willReturn(false);
        $ticketFolder->expects($this->once())->method('newFile')->with('photo.jpg', $this->isType('resource'))->willReturn($file);

        $file->method('getName')->willReturn('photo.jpg');
        $file->method('getSize')->willReturn(1234);

        $this->attachmentMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Attachment $a) use ($ticket) {
                return $a->getTicketId() === $ticket->getId()
                    && $a->getFileName() === 'photo.jpg'
                    && $a->getMimetype() === 'image/jpeg'
                    && $a->getSize() === 1234
                    && $a->getUploadedBy() === 'alice';
            }))
            ->willReturnArgument(0);

        $result = $this->service->addAttachment($ticket, 'alice', $this->makeTmpUpload(), 'photo.jpg', 'image/jpeg');

        $this->assertSame('photo.jpg', $result->getFileName());
    }

    public function testAddAttachmentPlacesFileUnderResolvedSubfolder(): void {
        $ticket = $this->makeTicket(8, 'resolved');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $resolvedFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();
        $file = $this->createMock(File::class);

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);

        // Pas trouvé dans Résolus/ (emplacement attendu), ni ailleurs (Tickets/ racine, Fermés/).
        $ticketsFolder->method('nodeExists')->willReturnMap([
            ['Résolus', true],
            [$ticketNumber, false],
            ['Fermés', false],
        ]);
        $ticketsFolder->method('get')->with('Résolus')->willReturn($resolvedFolder);
        $resolvedFolder->method('nodeExists')->with($ticketNumber)->willReturn(false);
        $resolvedFolder->expects($this->once())->method('newFolder')->with($ticketNumber)->willReturn($ticketFolder);

        $ticketFolder->method('nodeExists')->with('note.pdf')->willReturn(false);
        $ticketFolder->method('newFile')->willReturn($file);
        $file->method('getName')->willReturn('note.pdf');
        $file->method('getSize')->willReturn(10);
        $file->method('getMimeType')->willReturn('application/pdf');

        $this->attachmentMapper->method('insert')->willReturnArgument(0);

        $result = $this->service->addAttachment($ticket, 'bob', $this->makeTmpUpload(), 'note.pdf', null);

        // mimetype non fourni par l'appelant -> repli sur File::getMimeType().
        $this->assertSame('application/pdf', $result->getMimetype());
    }

    public function testAddAttachmentRelocatesFolderFoundInWrongStatusSubfolder(): void {
        // Ticket désormais "closed", mais son dossier existe encore à la racine
        // (créé avant le changement de statut) : il doit être déplacé vers Fermés/.
        $ticket = $this->makeTicket(9, 'closed');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $existingFolder = $this->folderMock();
        $fermesFolder = $this->folderMock();
        $movedFolder = $this->folderMock();
        $file = $this->createMock(File::class);

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);

        $ticketsFolder->method('nodeExists')->willReturnMap([
            ['Fermés', false], // emplacement attendu : pas encore de sous-dossier Fermés/
            [$ticketNumber, true], // trouvé à la racine (ancien emplacement)
        ]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($existingFolder);
        $ticketsFolder->expects($this->once())->method('newFolder')->with('Fermés')->willReturn($fermesFolder);
        $fermesFolder->method('getPath')->willReturn('/admin/files/Tickets/Fermés');
        $fermesFolder->method('get')->with($ticketNumber)->willReturn($movedFolder);

        $existingFolder->expects($this->once())->method('move')
            ->with('/admin/files/Tickets/Fermés/' . $ticketNumber);

        $movedFolder->method('nodeExists')->with('scan.png')->willReturn(false);
        $movedFolder->method('newFile')->willReturn($file);
        $file->method('getName')->willReturn('scan.png');
        $file->method('getSize')->willReturn(1);
        $file->method('getMimeType')->willReturn('image/png');

        $this->attachmentMapper->method('insert')->willReturnArgument(0);

        $this->service->addAttachment($ticket, 'carol', $this->makeTmpUpload(), 'scan.png', null);
    }

    public function testAddAttachmentSanitizesPathSeparatorsInFileName(): void {
        $ticket = $this->makeTicket(10, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();
        $file = $this->createMock(File::class);

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([
            [$ticketNumber, true],
        ]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($ticketFolder);

        $ticketFolder->method('nodeExists')->with('..__etc_passwd.txt')->willReturn(false);
        $ticketFolder->expects($this->once())->method('newFile')
            ->with('..__etc_passwd.txt', $this->isType('resource'))
            ->willReturn($file);
        $file->method('getName')->willReturn('..__etc_passwd.txt');
        $file->method('getSize')->willReturn(1);
        $file->method('getMimeType')->willReturn('text/plain');

        $this->attachmentMapper->method('insert')->willReturnArgument(0);

        // Séparateurs de chemin remplacés par "_" avant tout accès disque.
        $this->service->addAttachment($ticket, 'alice', $this->makeTmpUpload(), '../../etc/passwd.txt', null);
    }

    public function testAddAttachmentDedupesFileNameWithSuffix(): void {
        $ticket = $this->makeTicket(11, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();
        $file = $this->createMock(File::class);

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([[$ticketNumber, true]]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($ticketFolder);

        // "photo.jpg" et "photo (2).jpg" existent déjà -> "photo (3).jpg".
        $ticketFolder->method('nodeExists')->willReturnMap([
            ['photo.jpg', true],
            ['photo (2).jpg', true],
            ['photo (3).jpg', false],
        ]);
        $ticketFolder->expects($this->once())->method('newFile')
            ->with('photo (3).jpg', $this->isType('resource'))
            ->willReturn($file);
        $file->method('getName')->willReturn('photo (3).jpg');
        $file->method('getSize')->willReturn(1);
        $file->method('getMimeType')->willReturn('image/jpeg');

        $this->attachmentMapper->method('insert')->willReturnArgument(0);

        $result = $this->service->addAttachment($ticket, 'alice', $this->makeTmpUpload(), 'photo.jpg', null);

        $this->assertSame('photo (3).jpg', $result->getFileName());
    }

    // ---- getFolderUrl() -------------------------------------------------

    public function testGetFolderUrlBuildsInternalLinkFromFolderId(): void {
        $ticket = $this->makeTicket(12, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([[$ticketNumber, true]]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($ticketFolder);
        $ticketFolder->method('getId')->willReturn(4242);

        $this->urlGenerator->method('getAbsoluteURL')->with('/f/4242')->willReturn('https://cloud.example.org/f/4242');

        $this->assertSame('https://cloud.example.org/f/4242', $this->service->getFolderUrl($ticket));
    }

    public function testGetFolderUrlThrowsWhenTicketHasNoFolderYet(): void {
        $ticket = $this->makeTicket(13, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([
            [$ticketNumber, false],
            ['Résolus', false],
            ['Fermés', false],
        ]);

        $this->expectException(NotFoundException::class);
        $this->service->getFolderUrl($ticket);
    }

    // ---- deleteAttachment() ---------------------------------------------

    public function testDeleteAttachmentDeletesFileAndMetadata(): void {
        $ticket = $this->makeTicket(14, 'new');
        $ticketNumber = $ticket->getTicketNumber();
        $attachment = new Attachment();
        $attachment->setFileName('photo.jpg');

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();
        $file = $this->createMock(File::class);

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([[$ticketNumber, true]]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($ticketFolder);
        $ticketFolder->method('get')->with('photo.jpg')->willReturn($file);

        $file->expects($this->once())->method('delete');
        $this->attachmentMapper->expects($this->once())->method('delete')->with($attachment);

        $this->service->deleteAttachment($attachment, $ticket);
    }

    public function testDeleteAttachmentStillDeletesMetadataWhenFileAlreadyGone(): void {
        $ticket = $this->makeTicket(15, 'new');
        $ticketNumber = $ticket->getTicketNumber();
        $attachment = new Attachment();
        $attachment->setFileName('gone.jpg');

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([[$ticketNumber, true]]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($ticketFolder);
        // Le fichier n'existe plus sur le disque : Folder::get() lève NotFoundException.
        $ticketFolder->method('get')->with('gone.jpg')->willThrowException(new NotFoundException());

        $this->attachmentMapper->expects($this->once())->method('delete')->with($attachment);

        $this->service->deleteAttachment($attachment, $ticket);
    }

    // ---- deleteAllForTicket() ---------------------------------------------

    public function testDeleteAllForTicketSkipsFolderDeletionWhenNotConfigured(): void {
        $ticket = $this->makeTicket(16, 'new');
        $this->configService->method('getStorageAccountUid')->willReturn('');
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $this->attachmentMapper->expects($this->once())->method('deleteByTicket')->with(16);

        $this->service->deleteAllForTicket($ticket);
    }

    public function testDeleteAllForTicketDeletesFolderWhenPresent(): void {
        $ticket = $this->makeTicket(17, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolder = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([[$ticketNumber, true]]);
        $ticketsFolder->method('get')->with($ticketNumber)->willReturn($ticketFolder);

        $ticketFolder->expects($this->once())->method('delete');
        $this->attachmentMapper->expects($this->once())->method('deleteByTicket')->with(17);

        $this->service->deleteAllForTicket($ticket);
    }

    public function testDeleteAllForTicketDeletesMetadataEvenWhenNoFolderExisted(): void {
        $ticket = $this->makeTicket(18, 'new');
        $ticketNumber = $ticket->getTicketNumber();

        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('nodeExists')->willReturnMap([
            [$ticketNumber, false],
            ['Résolus', false],
            ['Fermés', false],
        ]);

        $this->attachmentMapper->expects($this->once())->method('deleteByTicket')->with(18);

        // Ne doit pas lever d'exception malgré l'absence de dossier.
        $this->service->deleteAllForTicket($ticket);
    }

    // ---- deleteAllTicketFolders() ---------------------------------------

    public function testDeleteAllTicketFoldersNoOpWhenNotConfigured(): void {
        $this->configService->method('getStorageAccountUid')->willReturn('');
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $this->service->deleteAllTicketFolders();
    }

    public function testDeleteAllTicketFoldersNoOpWhenTicketsFolderMissing(): void {
        $userFolder = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        // expects() plutôt que method() : ça sert aussi d'assertion — sans
        // elle, PHPUnit signale ce test comme "risky" (aucune assertion),
        // alors que son but est justement de vérifier qu'on s'arrête bien
        // au contrôle d'existence du dossier Tickets/ sans aller plus loin.
        $userFolder->expects($this->once())->method('nodeExists')->with('Tickets')->willReturn(false);

        // Ne doit pas lever d'exception malgré l'absence de dossier Tickets/.
        $this->service->deleteAllTicketFolders();
    }

    /**
     * Ticket 2.1 (retour d'admin) : le dossier Tickets/ lui-même a pu être créé
     * directement par l'utilisateur du compte de stockage (droits, partages...),
     * donc reset() ne doit vider que son CONTENU, pas supprimer le dossier Tickets/
     * lui-même.
     */
    public function testDeleteAllTicketFoldersDeletesContentsButKeepsTicketsFolderItself(): void {
        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $ticketFolderA = $this->folderMock();
        $ticketFolderB = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('getDirectoryListing')->willReturn([$ticketFolderA, $ticketFolderB]);

        $ticketsFolder->expects($this->never())->method('delete');
        $ticketFolderA->expects($this->once())->method('delete');
        $ticketFolderB->expects($this->once())->method('delete');

        $this->service->deleteAllTicketFolders();
    }

    public function testDeleteAllTicketFoldersContinuesWhenOneChildCannotBeDeleted(): void {
        $userFolder = $this->folderMock();
        $ticketsFolder = $this->folderMock();
        $stubborn = $this->folderMock();
        $deletable = $this->folderMock();

        $this->configService->method('getStorageAccountUid')->willReturn('admin');
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $userFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $userFolder->method('get')->with('Tickets')->willReturn($ticketsFolder);
        $ticketsFolder->method('getDirectoryListing')->willReturn([$stubborn, $deletable]);

        $stubborn->method('delete')->willThrowException(new NotPermittedException());
        $deletable->expects($this->once())->method('delete');

        $this->service->deleteAllTicketFolders();
    }

    // ---- migrateStorageAccount() ---------------------------------------

    public function testMigrateStorageAccountNoOpWhenUidsIdentical(): void {
        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->service->migrateStorageAccount('admin', 'admin');
    }

    public function testMigrateStorageAccountNoOpWhenEitherUidEmpty(): void {
        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->service->migrateStorageAccount('', 'admin');
        $this->service->migrateStorageAccount('admin', '');
    }

    public function testMigrateStorageAccountNoOpWhenOldAccountHasNoTicketsFolder(): void {
        $oldUserFolder = $this->folderMock();
        // Le nouveau compte ne doit même pas être consulté : rien à migrer, donc un
        // seul appel à getUserFolder() au total (pour l'ancien compte).
        $this->rootFolder->expects($this->once())->method('getUserFolder')->with('old-admin')->willReturn($oldUserFolder);
        $oldUserFolder->method('nodeExists')->with('Tickets')->willReturn(false);

        $this->service->migrateStorageAccount('old-admin', 'new-admin');
    }

    public function testMigrateStorageAccountNoOpWhenOldTicketsFolderIsEmpty(): void {
        $oldUserFolder = $this->folderMock();
        $oldTickets = $this->folderMock();
        $this->rootFolder->method('getUserFolder')->with('old-admin')->willReturn($oldUserFolder);
        $oldUserFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $oldUserFolder->method('get')->with('Tickets')->willReturn($oldTickets);
        $oldTickets->method('getDirectoryListing')->willReturn([]);

        $oldTickets->expects($this->never())->method('copy');
        $oldTickets->expects($this->never())->method('delete');

        $this->service->migrateStorageAccount('old-admin', 'new-admin');
    }

    public function testMigrateStorageAccountCopiesToFreshNewAccount(): void {
        $oldUserFolder = $this->folderMock();
        $oldTickets = $this->folderMock();
        $newUserFolder = $this->folderMock();

        $this->rootFolder->method('getUserFolder')->willReturnMap([
            ['old-admin', $oldUserFolder],
            ['new-admin', $newUserFolder],
        ]);
        $oldUserFolder->method('nodeExists')->with('Tickets')->willReturn(true);
        $oldUserFolder->method('get')->with('Tickets')->willReturn($oldTickets);
        $oldTickets->method('getDirectoryListing')->willReturn([$this->createMock(File::class)]);

        $newUserFolder->method('nodeExists')->with('Tickets')->willReturn(false);
        $newUserFolder->method('getPath')->willReturn('/new-admin/files');

        $oldTickets->expects($this->once())->method('copy')->with('/new-admin/files/Tickets');
        $oldTickets->expects($this->once())->method('delete');

        $this->service->migrateStorageAccount('old-admin', 'new-admin');
    }
}
