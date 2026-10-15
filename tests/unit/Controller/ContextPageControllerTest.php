<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\Controller\ContextPageController;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Service\ContextService;
use OCA\Tables\Service\GridWidgetTypes;
use OCA\Tables\Service\PageService;
use OCA\Tables\Service\PermissionsService;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContextPageControllerTest extends TestCase {
	private ContextPageController $controller;
	private PermissionsService&MockObject $permissionsService;
	private PageContentMapper&MockObject $pageContentMapper;
	private GridWidgetTypes $gridWidgetTypes;

	protected function setUp(): void {
		parent::setUp();
		$context = new Context();
		$context->id = 7;
		$context->setNodes([20 => ['id' => 20, 'node_id' => 3, 'node_type' => 0, 'permissions' => 1]]);
		$context->setPages([
			1 => ['id' => 1, 'page_type' => Page::TYPE_STARTPAGE, 'title' => null, 'technical_name' => 'dashboard', 'content' => []],
			2 => ['id' => 2, 'page_type' => Page::TYPE_GRID, 'title' => 'Sales', 'technical_name' => 'sales', 'content' => []],
		]);
		$contextMapper = $this->createMock(ContextMapper::class);
		$contextMapper->method('findById')->willReturnCallback(static function (int $contextId) use ($context): Context {
			if ($contextId !== 7) {
				throw new NotFoundError('Context does not exist');
			}
			return $context;
		});
		$this->permissionsService = $this->createMock(PermissionsService::class);
		$this->pageContentMapper = $this->createMock(PageContentMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->gridWidgetTypes = new GridWidgetTypes($l10n);

		$this->controller = new ContextPageController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
			'bob',
			new PageService(
				$contextMapper,
				$this->createMock(PageMapper::class),
				$this->pageContentMapper,
				$this->createMock(ContextService::class),
				$this->permissionsService,
				$this->gridWidgetTypes,
				$this->createMock(IDBConnection::class),
			),
			$this->gridWidgetTypes,
		);
	}

	public function testReadsReturnThePages(): void {
		$index = $this->controller->index(7);
		$show = $this->controller->show(7, 2);

		$this->assertSame(Http::STATUS_OK, $index->getStatus());
		$this->assertSame([1, 2], array_column($index->getData(), 'id'));
		$this->assertSame('sales', $show->getData()['technical_name']);
	}

	public function testUnknownApplicationOrPageIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->index(8)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(7, 9)->getStatus());
	}

	public function testWritesOfANonManagerAreForbidden(): void {
		$this->permissionsService->method('canManageContextById')->with(7, 'bob')->willReturn(false);
		$this->pageContentMapper->expects($this->never())->method('insert');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create(7, 'Overview')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->update(7, 2, 'Renamed')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->destroy(7, 2)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->saveWidgets(7, 2, [])->getStatus());
	}

	public function testAForeignNodeRelationIsABadRequest(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageContentMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveWidgets(7, 2, [
			['type' => 'data', 'nodeRelId' => 99, 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 5],
		]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAddingANodeTheManagerCannotManageIsForbidden(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->permissionsService->method('canManageNodeById')->willReturn(false);

		$response = $this->controller->saveWidgets(7, 2, [
			['type' => 'data', 'node' => ['nodeType' => 0, 'nodeId' => 4], 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 5],
		]);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAnInvalidOrDuplicateTechnicalNameIsABadRequest(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(7, 'Overview', 'Not valid')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(7, 'Overview', 'sales')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update(7, 2, null, 'dashboard')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update(7, 2, null, '')->getStatus());
	}

	public function testDeletingTheStartPageIsABadRequest(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->destroy(7, 1)->getStatus());
	}

	public function testWidgetTypesListsEveryType(): void {
		$types = $this->controller->widgetTypes()->getData();

		$this->assertSame(['header', 'text', 'data'], array_column($types, 'type'));
	}
}
