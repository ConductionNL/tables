<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Controller\ContextController;
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

class ContextControllerTest extends TestCase {
	private ContextService&MockObject $contextService;
	private ContextController $controller;

	protected function setUp(): void {
		$this->contextService = $this->createMock(ContextService::class);
		$this->controller = new ContextController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			'alice',
			$this->contextService,
			$this->createMock(TableService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ColumnService::class),
			$this->createMock(ViewService::class),
		);
	}

	public function testCreateRefusesANodeSlugThatIsNoString(): void {
		$this->contextService->expects($this->never())->method('create');

		$response = $this->controller->create('CRM', 'equalizer', '', [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'slug' => 42],
		]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateRefusesANodeSlugThatIsNoString(): void {
		$this->contextService->expects($this->never())->method('update');

		$response = $this->controller->update(7, null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 10, 'slug' => ['customers']],
		]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
