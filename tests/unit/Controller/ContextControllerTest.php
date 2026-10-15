<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\Controller\ContextController;
use OCA\Tables\Db\Context;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Model\ContextScheme;
use OCA\Tables\Model\MenuItemInput;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\ContextService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContextControllerTest extends TestCase {
	private ContextService&MockObject $contextService;
	private ContextController $controller;
	private IDBConnection&MockObject $db;

	private const TABLES = ['addTables' => [], 'modifyTables' => []];

	protected function setUp(): void {
		parent::setUp();
		$this->contextService = $this->createMock(ContextService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->controller = new ContextController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			'user-1',
			$this->contextService,
			$this->createMock(TableService::class),
			$this->db,
			$this->createMock(ColumnService::class),
			$this->createMock(ViewService::class),
		);
	}

	public function testCreatePassesAPageItemWithoutAnIdOnAsTheNewStartPage(): void {
		$context = new Context();
		$context->setMenuItems([]);
		$this->contextService->expects($this->once())->method('create')
			->with('Intake', 'star', '', [], 'user-1', 0, $this->callback(static function (array $menuItems): bool {
				return count($menuItems) === 1
					&& $menuItems[0] instanceof MenuItemInput
					&& $menuItems[0]->pointsAtNewStartPage()
					&& $menuItems[0]->getLabel() === 'Home';
			}))
			->willReturn($context);

		$response = $this->controller->create('Intake', 'star', '', [], [['label' => 'Home', 'targetType' => 'page']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testImportOfAMalformedSchemeIsABadRequestThatNeverReachesTheService(): void {
		$this->contextService->expects($this->never())->method('importScheme');

		$response = $this->controller->importScheme(7, 'Intake', 'star', '', [], ['addTables' => []]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testImportThatTheServiceRefusesAsABadRequestRollsBackWithA400(): void {
		$this->contextService->method('importScheme')->willThrowException(new BadRequestError('Unknown widget type'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$response = $this->controller->importScheme(7, 'Intake', 'star', '', [], self::TABLES);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testImportThatFailsInternallyRollsBackWithA500(): void {
		$this->contextService->method('importScheme')->willThrowException(new InternalError('insert failed'));
		$this->db->expects($this->once())->method('rollBack');

		$response = $this->controller->importScheme(7, 'Intake', 'star', '', [], self::TABLES);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testImportOfAPageThatIsGoneRollsBackWithA404(): void {
		$this->contextService->method('importScheme')->willThrowException(new NotFoundError('page not found'));
		$this->db->expects($this->once())->method('rollBack');

		$response = $this->controller->importScheme(7, 'Intake', 'star', '', [], self::TABLES);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testImportPassesTheMenuPagesAndGridViewsOnAndLeavesAbsentOnesNull(): void {
		$this->contextService->expects($this->exactly(2))->method('importScheme')
			->willReturnCallback(static function (int $contextId, ContextScheme $scheme): Context {
				static $call = 0;
				$call++;
				if ($call === 1) {
					TestCase::assertNull($scheme->getMenuItems());
					TestCase::assertNull($scheme->getPages());
					TestCase::assertSame([], $scheme->getGridViews());
				} else {
					TestCase::assertSame([], $scheme->getMenuItems());
					TestCase::assertSame([['id' => 3, 'page_type' => 'grid', 'title' => 'Reports']], $scheme->getPages());
					TestCase::assertSame('grid-1', $scheme->getGridViews()[0]['uuid']);
				}
				return new Context();
			});

		$this->controller->importScheme(7, 'Intake', 'star', '', [], self::TABLES);
		$this->controller->importScheme(7, 'Intake', 'star', '', [], self::TABLES, [], [['id' => 3, 'page_type' => 'grid', 'title' => 'Reports']], [['uuid' => 'grid-1', 'title' => 'Old page']]);
	}

	public function testUpdateRefusesAPageItemWithoutAnId(): void {
		$this->contextService->expects($this->never())->method('update');

		$response = $this->controller->update(7, null, null, null, null, [['label' => 'Home', 'targetType' => 'page']]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('needs its id', $response->getData()['message']);
	}
}
