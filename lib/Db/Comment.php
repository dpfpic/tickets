<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getAuthorUid()
 * @method void setAuthorUid(string $authorUid)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Comment extends Entity implements JsonSerializable {
    protected $ticketId = 0;
    protected $authorUid = '';
    protected $message = '';
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('ticketId', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'ticketId' => $this->getTicketId(),
            'authorUid' => $this->getAuthorUid(),
            'message' => $this->getMessage(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
