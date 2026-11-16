<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\Share;
use OCA\Tables\Db\ShareMapper;
use OCA\Tables\Helper\GroupHelper;
use OCA\Tables\Helper\UserHelper;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use Psr\Log\LoggerInterface;

class ContextMapperSlugTest extends DatabaseTestCase {
	private ContextMapper $contextMapper;
	private ShareMapper $shareMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->cleanupTablesData();

		$userHelper = $this->createMock(UserHelper::class);
		$userHelper->method('getGroupIdsForUser')->willReturnMap([
			['carol', ['sales']],
		]);
		$this->contextMapper = new ContextMapper($this->connectionAdapter, $userHelper, $this->createMock(GroupHelper::class));
		$this->shareMapper = new ShareMapper($this->createMock(LoggerInterface::class), $this->connectionAdapter);
	}

	protected function tearDown(): void {
		$this->cleanupTablesData();
		parent::tearDown();
	}

	public function testFindsTheOwnersApplication(): void {
		$id = $this->insertContext('alice', 'crm');

		$this->assertSame([$id], $this->contextMapper->findIdsBySlug('crm', 'alice'));
	}

	public function testDoesNotFindAnApplicationThatIsNotSharedWithTheUser(): void {
		$this->insertContext('alice', 'crm');

		$this->assertSame([], $this->contextMapper->findIdsBySlug('crm', 'bob'));
	}

	public function testFindsAnApplicationSharedWithTheUserOrTheirGroup(): void {
		$id = $this->insertContext('alice', 'crm');
		$this->share($id, 'user', 'bob');
		$this->share($id, 'group', 'sales');
		$this->share($id, 'user', 'carol');

		$this->assertSame([$id], $this->contextMapper->findIdsBySlug('crm', 'bob'));
		$this->assertSame([$id], $this->contextMapper->findIdsBySlug('crm', 'carol'));
	}

	public function testFindsEveryAccessibleApplicationWithTheSlugOnce(): void {
		$own = $this->insertContext('bob', 'crm');
		$shared = $this->insertContext('alice', 'crm');
		$this->share($shared, 'user', 'bob');
		$this->share($shared, 'user', 'dave');
		$this->insertContext('erin', 'crm');

		$ids = $this->contextMapper->findIdsBySlug('crm', 'bob');
		sort($ids);

		$this->assertSame([$own, $shared], $ids);
	}

	private function insertContext(string $ownerId, string $slug): int {
		$context = new Context();
		$context->setName('Application of ' . $ownerId);
		$context->setIcon('equalizer');
		$context->setDescription('');
		$context->setOwnerId($ownerId);
		$context->setOwnerType(Application::OWNER_TYPE_USER);
		$context->setSlug($slug);
		return $this->contextMapper->insert($context)->getId();
	}

	private function share(int $contextId, string $receiverType, string $receiver): void {
		$share = new Share();
		$share->setSender('alice');
		$share->setReceiver($receiver);
		$share->setReceiverType($receiverType);
		$share->setNodeId($contextId);
		$share->setNodeType('context');
		$share->setPermissionRead(true);
		$share->setPermissionCreate(false);
		$share->setPermissionUpdate(false);
		$share->setPermissionDelete(false);
		$share->setPermissionManage(false);
		$share->setCreatedAt('2026-11-16 12:00:00');
		$share->setLastEditAt('2026-11-16 12:00:00');
		$this->shareMapper->insert($share);
	}
}
