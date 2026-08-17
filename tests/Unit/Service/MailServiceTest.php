<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Service;

use OCA\Tickets\Db\Comment;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Service\ConfigService;
use OCA\Tickets\Service\MailService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Tickets\Service\MailService
 *
 * Se concentre sur la construction des listes de destinataires par
 * évènement (qui reçoit quoi, dédup, exclusions) plutôt que sur le contenu
 * des emails : IMailer est entièrement mocké, aucun envoi réel n'a lieu.
 */
class MailServiceTest extends \Test\TestCase {
    /** @var IMailer&\PHPUnit\Framework\MockObject\MockObject */
    private $mailer;
    /** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
    private $userManager;
    /** @var ConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private $configService;
    private MailService $service;

    /** @var string[] adresses passées à IMessage::setTo(), dans l'ordre d'envoi */
    private array $sentTo;

    protected function setUp(): void {
        parent::setUp();

        $this->mailer = $this->createMock(IMailer::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->configService = $this->createMock(ConfigService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/tickets/');

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, array $params = []) => $params === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $params)
        );
        $l10nFactory = $this->createMock(IL10NFactory::class);
        $l10nFactory->method('getUserLanguage')->willReturn('fr');
        $l10nFactory->method('findLanguage')->willReturn('fr');
        $l10nFactory->method('get')->willReturn($l10n);

        $this->mailer->method('validateMailAddress')->willReturn(true);

        $template = $this->createMock(IEMailTemplate::class);
        $template->method('setSubject')->willReturn(null);
        $this->mailer->method('createEMailTemplate')->willReturn($template);

        $this->sentTo = [];
        $message = $this->createMock(IMessage::class);
        $message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
            $this->sentTo[] = $to[0];
            return $message;
        });
        $message->method('useTemplate')->willReturn($message);
        $this->mailer->method('createMessage')->willReturn($message);

        $this->service = new MailService(
            $this->mailer,
            $this->userManager,
            $urlGenerator,
            $l10nFactory,
            $this->configService,
            $logger
        );
    }

    private function makeTicket(int $id, string $ownerUid, ?string $assignedUid = null): Ticket {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setTitle('Ascenseur bloqué entre le 2e et le 3e');
        $ticket->setCategory('elevator');
        $ticket->setStatus('new');
        $ticket->setOwnerUid($ownerUid);
        $ticket->setAssignedUid($assignedUid);
        $ticket->setCreatedAt(time());
        return $ticket;
    }

    /** @param array<string,string> $uidsToEmails */
    private function userManagerWithEmails(array $uidsToEmails): void {
        $map = [];
        foreach ($uidsToEmails as $uid => $email) {
            $user = $this->createMock(IUser::class);
            $user->method('getEMailAddress')->willReturn($email);
            $user->method('getDisplayName')->willReturn(ucfirst((string) $uid));
            $map[] = [$uid, $user];
        }
        $this->userManager->method('get')->willReturnMap($map);
    }

    public function testSendTicketCreatedNotifiesOwnerAndManagerBox(): void {
        $ticket = $this->makeTicket(1, 'alice');
        $this->userManagerWithEmails(['alice' => 'alice@example.org']);
        $this->configService->method('getManagerEmail')->willReturn('conseil-syndical@example.org');

        $this->service->sendTicketCreated($ticket);

        sort($this->sentTo);
        $this->assertSame(['alice@example.org', 'conseil-syndical@example.org'], $this->sentTo);
    }

    public function testSendTicketCreatedSkipsManagerBoxWhenNotConfigured(): void {
        $ticket = $this->makeTicket(2, 'alice');
        $this->userManagerWithEmails(['alice' => 'alice@example.org']);
        $this->configService->method('getManagerEmail')->willReturn('');

        $this->service->sendTicketCreated($ticket);

        $this->assertSame(['alice@example.org'], $this->sentTo);
    }

    public function testSendTicketCreatedSkipsOwnerSilentlyWhenNoEmailOnAccount(): void {
        $ticket = $this->makeTicket(3, 'alice');
        $this->userManagerWithEmails(['alice' => '']);
        $this->configService->method('getManagerEmail')->willReturn('conseil-syndical@example.org');

        $this->service->sendTicketCreated($ticket);

        // Pas d'exception, juste l'email skippé : seule la boîte gestionnaire reçoit le mail.
        $this->assertSame(['conseil-syndical@example.org'], $this->sentTo);
    }

    public function testSendTicketAssignedOnlyNotifiesManagerBoxNotOwner(): void {
        $ticket = $this->makeTicket(4, 'alice', 'bob');
        $this->userManagerWithEmails(['alice' => 'alice@example.org', 'bob' => 'bob@example.org']);
        $this->configService->method('getManagerEmail')->willReturn('conseil-syndical@example.org');

        $this->service->sendTicketAssigned($ticket);

        $this->assertSame(['conseil-syndical@example.org'], $this->sentTo);
    }

    public function testSendCommentAddedExcludesAuthorAndSkipsManagerBox(): void {
        // L'auteur du commentaire est l'assigné : il ne doit pas se notifier lui-même.
        $ticket = $this->makeTicket(5, 'alice', 'bob');
        $comment = new Comment();
        $comment->setTicketId(5);
        $comment->setAuthorUid('bob');
        $comment->setMessage('On regarde ça demain.');
        $this->userManagerWithEmails(['alice' => 'alice@example.org', 'bob' => 'bob@example.org']);
        // La boîte gestionnaire n'est jamais sollicitée sur ce chemin, mais on
        // s'assure qu'un éventuel appel ne casse pas le test.
        $this->configService->method('getManagerEmail')->willReturn('conseil-syndical@example.org');

        $this->service->sendCommentAdded($ticket, $comment);

        $this->assertSame(['alice@example.org'], $this->sentTo);
    }

    public function testSendCommentAddedDedupesWhenOwnerAndAssignedAreTheSameUid(): void {
        $ticket = $this->makeTicket(6, 'alice', 'alice');
        $comment = new Comment();
        $comment->setTicketId(6);
        $comment->setAuthorUid('bob');
        $comment->setMessage('Message');
        $this->userManagerWithEmails(['alice' => 'alice@example.org']);

        $this->service->sendCommentAdded($ticket, $comment);

        $this->assertSame(['alice@example.org'], $this->sentTo);
    }

    public function testSendTicketClosedExcludesActorButKeepsOthers(): void {
        $ticket = $this->makeTicket(7, 'alice', 'bob');
        $this->userManagerWithEmails(['alice' => 'alice@example.org', 'bob' => 'bob@example.org']);
        $this->configService->method('getManagerEmail')->willReturn('conseil-syndical@example.org');

        // C'est bob (l'assigné) qui clôture : il ne se notifie pas lui-même.
        $this->service->sendTicketClosed($ticket, 'bob');

        sort($this->sentTo);
        $this->assertSame(['alice@example.org', 'conseil-syndical@example.org'], $this->sentTo);
    }

    public function testSendOneSkipsInvalidAddressWithoutThrowing(): void {
        $ticket = $this->makeTicket(8, 'alice');
        $this->userManagerWithEmails(['alice' => 'not-an-email']);
        $this->configService->method('getManagerEmail')->willReturn('');
        $this->mailer->method('validateMailAddress')->willReturn(false);

        $this->service->sendTicketCreated($ticket);

        $this->assertSame([], $this->sentTo);
    }
}
