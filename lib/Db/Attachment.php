<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string|null getMimetype()
 * @method void setMimetype(?string $mimetype)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getUploadedBy()
 * @method void setUploadedBy(string $uploadedBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Attachment extends Entity implements JsonSerializable {
    protected $ticketId = 0;
    protected $fileName = '';
    protected $mimetype = null;
    protected $size = 0;
    protected $uploadedBy = '';
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('ticketId', 'integer');
        $this->addType('size', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'ticketId' => $this->getTicketId(),
            'fileName' => $this->getFileName(),
            'mimetype' => $this->getMimetype(),
            'size' => $this->getSize(),
            'uploadedBy' => $this->getUploadedBy(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
