<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getReadAt()
 * @method void setReadAt(int $readAt)
 */
class TicketRead extends Entity {
    protected $ticketId = 0;
    protected $uid = '';
    protected $readAt = 0;

    public function __construct() {
        $this->addType('ticketId', 'integer');
        $this->addType('readAt', 'integer');
    }
}
