<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Controller;

use OCA\Tickets\Controller\PageController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * @covers \OCA\Tickets\Controller\PageController
 */
class PageControllerTest extends \Test\TestCase {
    private PageController $controller;

    protected function setUp(): void {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->controller = new PageController($request);
    }

    public function testIndexRendersMainTemplate(): void {
        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('main', $response->getTemplateName());
    }
}
