<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getOldValue()
 * @method void setOldValue(?string $oldValue)
 * @method string|null getNewValue()
 * @method void setNewValue(?string $newValue)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Activity extends Entity implements JsonSerializable {
    protected $ticketId = 0;
    protected $actorUid = '';
    protected $type = '';
    protected $oldValue = null;
    protected $newValue = null;
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('ticketId', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'ticketId' => $this->getTicketId(),
            'actorUid' => $this->getActorUid(),
            'type' => $this->getType(),
            'oldValue' => $this->getOldValue(),
            'newValue' => $this->getNewValue(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
