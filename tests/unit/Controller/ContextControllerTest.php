<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\Controller\ContextController;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\InternalError;
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
	private const TABLES = ['addTables' => [], 'modifyTables' => []];

	private ContextController $controller;
	private MockObject $contextService;
	private MockObject $db;

	protected function setUp(): void {
		parent::setUp();
		$this->contextService = $this->createMock(ContextService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->controller = new ContextController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
			'alice',
			$this->contextService,
			$this->createMock(TableService::class),
			$this->db,
			$this->createMock(ColumnService::class),
			$this->createMock(ViewService::class),
		);
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
}
