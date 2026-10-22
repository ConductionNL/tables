<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\Controller\PageController;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Service\NodeService;
use OCA\Tables\Service\ShareService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\INavigationManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase {
	private IInitialState&MockObject $initialState;
	private ContextMapper&MockObject $contextMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->initialState = $this->createMock(IInitialState::class);
		$this->contextMapper = $this->createMock(ContextMapper::class);
	}

	private function controller(?string $userId): PageController {
		return new PageController(
			$this->createMock(IRequest::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(INavigationManager::class),
			$this->initialState,
			$this->createMock(ShareService::class),
			$this->createMock(NodeService::class),
			$this->contextMapper,
			$userId,
		);
	}

	public function testAnApplicationIsOpenedByItsId(): void {
		$this->contextMapper->expects($this->never())->method('findIdByTechnicalName');
		$this->initialState->expects($this->once())->method('provideInitialState')->with('contextId', 28);

		$this->controller('alice')->context('28');
	}

	public function testAnApplicationIsOpenedByItsTechnicalName(): void {
		$this->contextMapper->expects($this->once())->method('findIdByTechnicalName')->with('intake', 'alice')->willReturn(28);
		$this->initialState->expects($this->once())->method('provideInitialState')->with('contextId', 28);

		$this->controller('alice')->context('intake');
	}

	public function testAnUnknownTechnicalNameOpensTheStartPageOfTables(): void {
		$this->contextMapper->method('findIdByTechnicalName')->willReturn(null);
		$this->initialState->expects($this->never())->method('provideInitialState');

		$this->controller('alice')->context('nothing_here');
	}

	public function testATechnicalNameIsNotLookedUpWithoutAUser(): void {
		$this->contextMapper->expects($this->never())->method('findIdByTechnicalName');
		$this->initialState->expects($this->never())->method('provideInitialState');

		$this->controller(null)->context('intake');
	}
}
