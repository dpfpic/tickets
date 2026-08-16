<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Controller;

use OCA\Tickets\Controller\TicketController;
use OCA\Tickets\Db\ActivityMapper;
use OCA\Tickets\Db\Attachment;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\CommentMapper;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Db\TicketReadMapper;
use OCA\Tickets\Service\AttachmentService;
use OCA\Tickets\Service\ConfigService;
use OCA\Tickets\Service\NotificationService;
use OCA\Tickets\Service\XlsxWriter;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * @covers \OCA\Tickets\Controller\TicketController
 */
class TicketControllerTest extends \Test\TestCase {
    /** @var TicketMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $ticketMapper;
    /** @var CommentMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $commentMapper;
    /** @var TicketReadMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $ticketReadMapper;
    /** @var AttachmentMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $attachmentMapper;
    /** @var ActivityMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $activityMapper;
    /** @var AttachmentService&\PHPUnit\Framework\MockObject\MockObject */
    private $attachmentService;
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private $groupManager;
    /** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
    private $userSession;
    /** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
    private $userManager;
    /** @var IAccountManager&\PHPUnit\Framework\MockObject\MockObject */
    private $accountManager;
    /** @var NotificationService&\PHPUnit\Framework\MockObject\MockObject */
    private $notificationService;
    /** @var ConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private $configService;
    /** @var XlsxWriter&\PHPUnit\Framework\MockObject\MockObject */
    private $xlsxWriter;

    private TicketController $controller;

    protected function setUp(): void {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->commentMapper = $this->createMock(CommentMapper::class);
        $this->ticketReadMapper = $this->createMock(TicketReadMapper::class);
        $this->attachmentMapper = $this->createMock(AttachmentMapper::class);
        $this->activityMapper = $this->createMock(ActivityMapper::class);
        $this->attachmentService = $this->createMock(AttachmentService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->xlsxWriter = $this->createMock(XlsxWriter::class);

        $this->controller = new TicketController(
            $request,
            $this->ticketMapper,
            $this->commentMapper,
            $this->ticketReadMapper,
            $this->attachmentMapper,
            $this->activityMapper,
            $this->attachmentService,
            $this->groupManager,
            $this->userSession,
            $this->userManager,
            $this->accountManager,
            $this->notificationService,
            $this->configService,
            $this->xlsxWriter
        );
    }

    private function makeUser(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($uid);
        return $user;
    }

    private function makeTicket(int $id, string $ownerUid = 'alice', string $status = 'new'): Ticket {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setOwnerUid($ownerUid);
        $ticket->setStatus($status);
        $ticket->setCreatedAt(mktime(12, 0, 0, 6, 1, 2026));
        $ticket->setUpdatedAt(mktime(12, 0, 0, 6, 1, 2026));
        return $ticket;
    }

    private function makeAttachment(int $id, int $ticketId, string $fileName): Attachment {
        $attachment = new Attachment();
        $attachment->setId($id);
        $attachment->setTicketId($ticketId);
        $attachment->setFileName($fileName);
        $attachment->setMimetype('application/pdf');
        return $attachment;
    }

    /** ---- destroy() : réservé au groupe gestionnaire, doit nettoyer TOUTES les données liées ---- */

    public function testDestroyDeniedForNonBoardMember(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->configService->method('getBoardGroups')->willReturn(['syndic']);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->ticketMapper->expects($this->never())->method('delete');

        $response = $this->controller->destroy(1);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testDestroyReturnsNotFoundForMissingTicket(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('board'));
        $this->configService->method('getBoardGroups')->willReturn(['syndic']);
        $this->groupManager->method('isInGroup')->willReturn(true);
        $this->ticketMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->destroy(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testDestroyCleansUpAllRelatedDataForBoardMember(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('board'));
        $this->configService->method('getBoardGroups')->willReturn(['syndic']);
        $this->groupManager->method('isInGroup')->willReturn(true);

        $ticket = $this->makeTicket(42);
        $this->ticketMapper->method('find')->with(42)->willReturn($ticket);

        $this->attachmentService->expects($this->once())->method('deleteAllForTicket')->with($ticket);
        $this->commentMapper->expects($this->once())->method('deleteByTicket')->with(42);
        $this->activityMapper->expects($this->once())->method('deleteByTicket')->with(42);
        $this->ticketReadMapper->expects($this->once())->method('deleteByTicket')->with(42);
        $this->ticketMapper->expects($this->once())->method('delete')->with($ticket);

        $response = $this->controller->destroy(42);

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    /** ---- create() ---- */

    public function testCreateRejectsEmptyTitle(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->configService->method('getRequesterGroups')->willReturn([]);
        $this->configService->method('getBoardGroups')->willReturn([]);

        $response = $this->controller->create('   ');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testCreateDeniedWhenUserCannotRequest(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->configService->method('getBoardGroups')->willReturn(['syndic']);
        $this->configService->method('getRequesterGroups')->willReturn(['residents']);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->ticketMapper->expects($this->never())->method('insert');

        $response = $this->controller->create('Fuite d\'eau');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    /** ---- update() ---- */

    public function testUpdateReturnsNotFoundForMissingTicket(): void {
        $this->ticketMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->update(999, title: 'Nouveau titre');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testUpdateDeniedForUnrelatedUser(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('mallory'));
        $this->configService->method('getBoardGroups')->willReturn(['syndic']);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $ticket = $this->makeTicket(1, 'alice');
        $this->ticketMapper->method('find')->willReturn($ticket);

        $response = $this->controller->update(1, title: 'Titre modifié');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    /** ---- downloadAttachment() : accès + en-tête Content-Disposition (ticket 1.3) ---- */

    public function testDownloadAttachmentDeniedWhenAttachmentBelongsToAnotherTicket(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->configService->method('getBoardGroups')->willReturn([]);

        $ticket = $this->makeTicket(1, 'alice');
        $attachment = $this->makeAttachment(5, 2, 'devis.pdf'); // ticketId=2 != ticket id=1
        $this->ticketMapper->method('find')->willReturn($ticket);
        $this->attachmentMapper->method('find')->willReturn($attachment);

        $response = $this->controller->downloadAttachment(1, 5);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testDownloadAttachmentSetsRfc5987CompliantContentDisposition(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->configService->method('getBoardGroups')->willReturn([]);

        $ticket = $this->makeTicket(1, 'alice');
        // Nom de fichier accentué : c'est justement ce que l'en-tête doit gérer
        // correctement (voir ticket 1.3 - RFC 5987/6266).
        $attachment = $this->makeAttachment(5, 1, 'Relevé de charges été.pdf');
        $this->ticketMapper->method('find')->willReturn($ticket);
        $this->attachmentMapper->method('find')->willReturn($attachment);

        $stream = fopen('php://memory', 'r');
        $file = $this->createMock(File::class);
        $file->method('fopen')->willReturn($stream);
        $this->attachmentService->method('getFile')->willReturn($file);

        $response = $this->controller->downloadAttachment(1, 5);

        $this->assertInstanceOf(StreamResponse::class, $response);
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Disposition', $headers);
        $disposition = $headers['Content-Disposition'];

        // Repli ASCII pour les clients qui ignorent filename*, sans accent ni guillemet.
        $this->assertMatchesRegularExpression('/filename="[^"\x80-\xff]*"/', $disposition);
        // Paramètre étendu RFC 5987, pourcent-encodé, avec le nom d'origine préservé.
        $this->assertStringContainsString(
            "filename*=UTF-8''" . rawurlencode('Relevé de charges été.pdf'),
            $disposition
        );
    }

    /** ---- attachmentsFolder() ---- */

    public function testAttachmentsFolderDeniedForNonBoardMember(): void {
        $this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
        $this->configService->method('getBoardGroups')->willReturn(['syndic']);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $response = $this->controller->attachmentsFolder(1);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }
}
