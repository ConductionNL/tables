<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\Controller\ContextPageController;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Service\GridWidgetTypes;
use OCA\Tables\Service\PageService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContextPageControllerTest extends TestCase {
	private ContextPageController $controller;
	private PageService&MockObject $pageService;

	protected function setUp(): void {
		parent::setUp();
		$this->pageService = $this->createMock(PageService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->controller = new ContextPageController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
			'alice',
			$this->pageService,
			new GridWidgetTypes($l10n),
		);
	}

	public function testShowReturnsThePage(): void {
		$page = ['id' => 2, 'contextId' => 7, 'pageType' => 'grid', 'title' => 'Overview', 'emoji' => null, 'description' => null, 'widgets' => []];
		$this->pageService->method('find')->with(7, 2, 'alice')->willReturn($page);

		$response = $this->controller->show(7, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($page, $response->getData());
	}

	public function testShowAnUnknownPageIsNotFound(): void {
		$this->pageService->method('find')->willThrowException(new NotFoundError('Page 9 does not belong to application 7'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(7, 9)->getStatus());
	}

	public function testCreatePassesThePageFields(): void {
		$this->pageService->expects($this->once())->method('create')->with(7, 'Overview', '📊', 'At a glance', 'overview', 'alice')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->create(7, 'Overview', '📊', 'At a glance', 'overview')->getStatus());
	}

	public function testUpdatePassesFieldsAndWidgets(): void {
		$widgets = [['type' => 'text', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2]];
		$this->pageService->expects($this->once())->method('update')->with(7, 2, null, '', null, $widgets, 'alice', 'board')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->update(7, 2, null, '', null, $widgets, 'board')->getStatus());
	}

	public static function errors(): array {
		return [
			'bad request' => [new BadRequestError('Widget 0 needs a type.'), Http::STATUS_BAD_REQUEST],
			'no permission' => [new PermissionError('User bob cannot manage application 7'), Http::STATUS_FORBIDDEN],
			'not found' => [new NotFoundError('Context does not exist'), Http::STATUS_NOT_FOUND],
			'page gone' => [new DoesNotExistException('Page does not exist'), Http::STATUS_NOT_FOUND],
		];
	}

	/**
	 * @dataProvider errors
	 */
	public function testUpdateMapsErrorsToStatusCodes(\Exception $error, int $status): void {
		$this->pageService->method('update')->willThrowException($error);

		$response = $this->controller->update(7, 2, 'New');

		$this->assertSame($status, $response->getStatus());
		$this->assertArrayHasKey('message', $response->getData());
	}

	public function testABadRequestExplainsWhy(): void {
		$this->pageService->method('update')->willThrowException(new BadRequestError('Widget 0 needs a type.'));

		$this->assertSame('Widget 0 needs a type.', $this->controller->update(7, 2, null, null, null, [[]])->getData()['message']);
	}

	public static function serviceErrors(): array {
		return array_slice(self::errors(), 0, 3);
	}

	/**
	 * @dataProvider serviceErrors
	 */
	public function testCreateAndDestroyMapErrorsToStatusCodes(\Exception $error, int $status): void {
		$this->pageService->method('create')->willThrowException($error);
		$this->pageService->method('delete')->willThrowException($error);

		$this->assertSame($status, $this->controller->create(7, 'Overview')->getStatus());
		$this->assertSame($status, $this->controller->destroy(7, 2)->getStatus());
	}

	public function testWidgetTypesListsEveryType(): void {
		$response = $this->controller->widgetTypes();

		$this->assertSame(['header', 'text', 'data'], array_column($response->getData(), 'type'));
	}
}
