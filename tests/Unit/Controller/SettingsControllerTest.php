<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Controller;

use OCA\Tickets\Controller\SettingsController;
use OCA\Tickets\Db\ActivityMapper;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\CommentMapper;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Db\TicketReadMapper;
use OCA\Tickets\Service\AttachmentService;
use OCA\Tickets\Service\ConfigService;
use OCA\Tickets\Service\NotificationService;
use OCA\Tickets\Service\XlsxWriter;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Mail\IMailer;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Tickets\Controller\SettingsController
 */
class SettingsControllerTest extends \Test\TestCase {
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private $groupManager;
    /** @var ConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private $configService;
    /** @var NotificationService&\PHPUnit\Framework\MockObject\MockObject */
    private $notificationService;
    /** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
    private $userSession;
    /** @var TicketMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $ticketMapper;
    /** @var CommentMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $commentMapper;
    /** @var XlsxWriter&\PHPUnit\Framework\MockObject\MockObject */
    private $xlsxWriter;
    /** @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject */
    private $db;
    /** @var IMailer&\PHPUnit\Framework\MockObject\MockObject */
    private $mailer;
    /** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
    private $userManager;
    /** @var AttachmentService&\PHPUnit\Framework\MockObject\MockObject */
    private $attachmentService;
    /** @var AttachmentMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $attachmentMapper;
    /** @var ActivityMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $activityMapper;
    /** @var TicketReadMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $ticketReadMapper;

    private SettingsController $controller;

    protected function setUp(): void {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->commentMapper = $this->createMock(CommentMapper::class);
        $this->xlsxWriter = $this->createMock(XlsxWriter::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->mailer = $this->createMock(IMailer::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->attachmentService = $this->createMock(AttachmentService::class);
        $this->attachmentMapper = $this->createMock(AttachmentMapper::class);
        $this->activityMapper = $this->createMock(ActivityMapper::class);
        $this->ticketReadMapper = $this->createMock(TicketReadMapper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SettingsController(
            $request,
            $this->groupManager,
            $this->configService,
            $this->notificationService,
            $this->userSession,
            $this->ticketMapper,
            $this->commentMapper,
            $this->xlsxWriter,
            $this->db,
            $this->mailer,
            $this->userManager,
            $this->attachmentService,
            $this->attachmentMapper,
            $this->activityMapper,
            $this->ticketReadMapper,
            $logger
        );
    }

    public function testResetRequiresExactConfirmationWord(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->attachmentService->expects($this->never())->method('deleteAllTicketFolders');

        $response = $this->controller->reset('nope');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Ticket 2.1 : un reset() confirmé doit désormais nettoyer TOUTES les données
     * liées aux tickets (pas seulement les tables tickets/tickets_comments), sans
     * quoi les pièces jointes (fichiers + métadonnées), l'activité et les
     * marqueurs de lecture restaient orphelins.
     */
    public function testResetWithValidConfirmationDeletesAllRelatedData(): void {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->expects($this->exactly(2))->method('executeStatement');
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->attachmentService->expects($this->once())->method('deleteAllTicketFolders');
        $this->attachmentMapper->expects($this->once())->method('deleteAll');
        $this->activityMapper->expects($this->once())->method('deleteAll');
        $this->ticketReadMapper->expects($this->once())->method('deleteAll');

        $response = $this->controller->reset('RESET');

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    public function testSaveConfigRejectsEmptyBoardGroups(): void {
        $this->configService->method('getBoardGroups')->willReturn([]);
        $this->configService->method('getRequesterGroups')->willReturn([]);
        $this->configService->method('getCategories')->willReturn([]);
        $this->configService->method('getManagerEmail')->willReturn('');
        $this->configService->method('getOpenInNewTab')->willReturn(true);
        $this->configService->method('getLocationLabelFr')->willReturn('');
        $this->configService->method('getLocationLabelEn')->willReturn('');
        $this->configService->method('getDueDateEnabled')->willReturn(true);
        $this->configService->method('getAllowedExtensions')->willReturn([]);
        $this->configService->method('getMaxAttachmentSizeMb')->willReturn(20);

        $response = $this->controller->saveConfig([], [], [['label_fr' => 'Plomberie']]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('At least one board group is required', $response->getData()['message']);
    }

    public function testSaveConfigRejectsUnknownBoardGroup(): void {
        $this->stubCurrentConfig();
        $this->groupManager->method('groupExists')->willReturn(false);

        $response = $this->controller->saveConfig([], ['syndic'], [['label_fr' => 'Plomberie']]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid board group', $response->getData()['message']);
    }

    public function testSaveConfigRejectsCategoriesWithoutAnyLabel(): void {
        $this->stubCurrentConfig();
        $this->groupManager->method('groupExists')->willReturn(true);

        $response = $this->controller->saveConfig([], ['syndic'], [['label_fr' => '', 'label_en' => '']]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testSaveConfigRejectsTooSmallMaxAttachmentSize(): void {
        $this->stubCurrentConfig();
        $this->groupManager->method('groupExists')->willReturn(true);

        $response = $this->controller->saveConfig(
            [],
            ['syndic'],
            [['label_fr' => 'Plomberie']],
            '',
            '',
            true,
            '',
            '',
            true,
            ['pdf'],
            0
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Maximum attachment size must be at least 1 MB', $response->getData()['message']);
    }

    public function testGroupsListsAllNextcloudGroups(): void {
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('syndic');
        $group->method('getDisplayName')->willReturn('Conseil syndical');
        $this->groupManager->method('search')->with('')->willReturn([$group]);

        $response = $this->controller->groups();

        $this->assertSame(
            [['id' => 'syndic', 'displayName' => 'Conseil syndical']],
            $response->getData()
        );
    }

    private function stubCurrentConfig(): void {
        $this->configService->method('getBoardGroups')->willReturn([]);
        $this->configService->method('getRequesterGroups')->willReturn([]);
        $this->configService->method('getCategories')->willReturn([]);
        $this->configService->method('getManagerEmail')->willReturn('');
        $this->configService->method('getOpenInNewTab')->willReturn(true);
        $this->configService->method('getLocationLabelFr')->willReturn('');
        $this->configService->method('getLocationLabelEn')->willReturn('');
        $this->configService->method('getDueDateEnabled')->willReturn(true);
        $this->configService->method('getAllowedExtensions')->willReturn(['pdf']);
        $this->configService->method('getMaxAttachmentSizeMb')->willReturn(20);
        $this->configService->method('getStorageAccountUid')->willReturn('');
    }
}
