<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Service;

use OCA\Tickets\Db\Comment;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Service\ConfigService;
use OCA\Tickets\Service\MailService;
use OCA\Tickets\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/**
 * @covers \OCA\Tickets\Service\NotificationService
 */
class NotificationServiceTest extends \Test\TestCase {
    /** @var INotificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private $notificationManager;
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private $groupManager;
    /** @var ConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private $configService;
    /** @var MailService&\PHPUnit\Framework\MockObject\MockObject */
    private $mailService;
    private NotificationService $service;

    /** @var string[] uids passés à setUser(), dans l'ordre d'appel */
    private array $notifiedUids;

    protected function setUp(): void {
        parent::setUp();

        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->mailService = $this->createMock(MailService::class);

        $this->service = new NotificationService(
            $this->notificationManager,
            $this->groupManager,
            $this->configService,
            $this->mailService
        );

        $this->notifiedUids = [];
        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification) {
            $this->notifiedUids[] = $uid;
            return $notification;
        });
        $this->notificationManager->method('createNotification')->willReturn($notification);
    }

    private function makeTicket(int $id, string $ownerUid, ?string $assignedUid = null): Ticket {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setTitle('Fuite robinet cuisine');
        $ticket->setOwnerUid($ownerUid);
        $ticket->setAssignedUid($assignedUid);
        $ticket->setCreatedAt(time());
        return $ticket;
    }

    private function group(string $gid, array $uids): IGroup {
        $group = $this->createMock(IGroup::class);
        $users = array_map(function (string $uid) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        }, $uids);
        $group->method('getUsers')->willReturn($users);
        return $group;
    }

    public function testNotifyTicketCreatedSkipsOwnerAndDedupesAcrossGroups(): void {
        $ticket = $this->makeTicket(1, 'alice');

        $this->configService->method('getBoardGroups')->willReturn(['board', 'board-bis']);
        $this->groupManager->method('get')->willReturnMap([
            ['board', $this->group('board', ['alice', 'bob', 'carol'])],
            // "bob" appartient aux deux groupes gestionnaires : ne doit être
            // notifié qu'une seule fois.
            ['board-bis', $this->group('board-bis', ['bob', 'dave'])],
        ]);

        $this->mailService->expects($this->once())->method('sendTicketCreated')->with($ticket);

        $this->service->notifyTicketCreated($ticket);

        // alice = auteur du ticket -> jamais notifiée ; bob une seule fois
        // malgré son appartenance aux deux groupes.
        sort($this->notifiedUids);
        $this->assertSame(['bob', 'carol', 'dave'], $this->notifiedUids);
    }

    public function testNotifyTicketCreatedIgnoresUnknownGroup(): void {
        $ticket = $this->makeTicket(2, 'alice');

        $this->configService->method('getBoardGroups')->willReturn(['deleted-group']);
        $this->groupManager->method('get')->with('deleted-group')->willReturn(null);

        $this->mailService->expects($this->once())->method('sendTicketCreated');

        $this->service->notifyTicketCreated($ticket);

        $this->assertSame([], $this->notifiedUids);
    }

    public function testNotifyTicketAssignedDoesNothingWhenNotAssigned(): void {
        $ticket = $this->makeTicket(3, 'alice', null);

        $this->mailService->expects($this->never())->method('sendTicketAssigned');

        $this->service->notifyTicketAssigned($ticket);
    }

    public function testNotifyTicketAssignedDelegatesToMailWhenAssigned(): void {
        $ticket = $this->makeTicket(4, 'alice', 'bob');

        $this->mailService->expects($this->once())->method('sendTicketAssigned')->with($ticket);

        $this->service->notifyTicketAssigned($ticket);
    }

    public function testNotifyTicketClosedDelegatesToMail(): void {
        $ticket = $this->makeTicket(5, 'alice', 'bob');

        $this->mailService->expects($this->once())
            ->method('sendTicketClosed')
            ->with($ticket, 'bob');

        $this->service->notifyTicketClosed($ticket, 'bob');
    }

    public function testNotifyStatusChangedDoesNothingIfStatusUnchanged(): void {
        $ticket = $this->makeTicket(6, 'alice');
        $ticket->setStatus('in_progress');

        $this->notificationManager->expects($this->never())->method('notify');

        $this->service->notifyStatusChanged($ticket, 'in_progress', 'bob');
    }

    public function testNotifyStatusChangedDoesNothingIfActorIsOwner(): void {
        $ticket = $this->makeTicket(7, 'alice');
        $ticket->setStatus('resolved');

        $this->notificationManager->expects($this->never())->method('notify');

        $this->service->notifyStatusChanged($ticket, 'new', 'alice');
    }

    public function testNotifyStatusChangedNotifiesOwnerWhenChangedByOther(): void {
        $ticket = $this->makeTicket(8, 'alice');
        $ticket->setStatus('resolved');

        $this->notificationManager->expects($this->once())->method('notify');

        $this->service->notifyStatusChanged($ticket, 'new', 'bob');

        $this->assertSame(['alice'], $this->notifiedUids);
    }

    public function testNotifyCommentAddedExcludesAuthorAndDedupesRoles(): void {
        // L'auteur du commentaire est aussi l'assigné du ticket : il ne doit
        // recevoir aucune notification pour son propre message.
        $ticket = $this->makeTicket(9, 'alice', 'bob');
        $comment = new Comment();
        $comment->setTicketId(9);
        $comment->setAuthorUid('bob');
        $comment->setMessage('Pris en compte, on regarde ça cette semaine.');

        $this->mailService->expects($this->once())
            ->method('sendCommentAdded')
            ->with($ticket, $comment);

        $this->service->notifyCommentAdded($ticket, $comment);

        $this->assertSame(['alice'], $this->notifiedUids);
    }

    public function testNotifyCommentAddedNotifiesOwnerAndAssignedWhenDistinctFromAuthor(): void {
        $ticket = $this->makeTicket(10, 'alice', 'bob');
        $comment = new Comment();
        $comment->setTicketId(10);
        $comment->setAuthorUid('carol');
        $comment->setMessage('Message tiers');

        $this->service->notifyCommentAdded($ticket, $comment);

        sort($this->notifiedUids);
        $this->assertSame(['alice', 'bob'], $this->notifiedUids);
    }

    public function testNotifyConfigSavedExcludesActorAndDedupesAcrossGroups(): void {
        $this->groupManager->method('get')->willReturnMap([
            ['board', $this->group('board', ['alice', 'bob'])],
            ['board-bis', $this->group('board-bis', ['bob', 'carol'])],
        ]);

        $this->service->notifyConfigSaved(['board', 'board-bis'], 'alice');

        sort($this->notifiedUids);
        $this->assertSame(['bob', 'carol'], $this->notifiedUids);
    }
}
