<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Controller\RowOCSController;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\Row2;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\View;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\Service\ContextService;
use OCA\Tables\Service\FederationService;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RowOCSControllerTest extends TestCase {
	private const UUID = '0199e3a0-1f2b-7c3d-8e4f-5a6b7c8d9e0f';

	private RowService&MockObject $rowService;
	private TableService&MockObject $tableService;
	private FederationService&MockObject $federationService;
	private ContextService&MockObject $contextService;
	private ViewService&MockObject $viewService;
	/** @var array<string, bool> */
	private array $deniedPermissions = [];
	private RowOCSController $controller;

	protected function setUp(): void {
		$this->rowService = $this->createMock(RowService::class);
		$this->tableService = $this->createMock(TableService::class);
		$this->federationService = $this->createMock(FederationService::class);
		$this->contextService = $this->createMock(ContextService::class);
		$this->viewService = $this->createMock(ViewService::class);
		$permissionsService = $this->createMock(PermissionsService::class);
		foreach (['canAccessNodeById', 'canCreateRowsById', 'canUpdateRowsByTableId', 'canUpdateRowsByViewId', 'canDeleteRowsByTableId', 'canDeleteRowsByViewId'] as $check) {
			$permissionsService->method($check)->willReturnCallback(fn (): bool => !isset($this->deniedPermissions[$check]));
		}
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->controller = new RowOCSController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
			'user1',
			$this->rowService,
			$this->tableService,
			$this->viewService,
			$this->federationService,
			$this->contextService,
			$permissionsService,
		);
	}

	private function row(int $rowId): Row2 {
		$row = new Row2();
		$row->setId($rowId);
		$row->setUuid(self::UUID);
		$row->setTableId(3);
		return $row;
	}

	private function contextNode(int $nodeType, int $nodeId): void {
		$nodeRelation = new ContextNodeRelation();
		$nodeRelation->setNodeType($nodeType);
		$nodeRelation->setNodeId($nodeId);
		$this->contextService->method('resolveNode')->with('crm', 'clients', 'user1')->willReturn($nodeRelation);
	}

	public function testGetRowResolvesTheIdentifierWithinTheTable(): void {
		$this->rowService->expects($this->once())
			->method('resolveRowId')
			->with(self::UUID, 3, null)
			->willReturn(7);
		$this->rowService->expects($this->once())
			->method('findInNode')
			->with(7, 3, null, 'user1')
			->willReturn($this->row(7));

		$response = $this->controller->getRow('tables', 3, self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::UUID, $response->getData()['uuid']);
	}

	public function testGetRowResolvesTheIdentifierWithinTheView(): void {
		$this->rowService->expects($this->once())
			->method('resolveRowId')
			->with('7', null, 5)
			->willReturn(7);
		$this->rowService->method('findInNode')->with(7, null, 5, 'user1')->willReturn($this->row(7));

		$this->assertSame(Http::STATUS_OK, $this->controller->getRow('views', 5, '7')->getStatus());
	}

	public function testGetRowReturnsNotFoundForAUuidOfAnotherTable(): void {
		$this->rowService->method('resolveRowId')->willThrowException(new NotFoundError('Row not found.'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->getRow('tables', 3, self::UUID)->getStatus());
	}

	public function testGetRowIsNotSupportedOnAFederatedTable(): void {
		$this->federationService->method('isNodeFederated')->with(3, 'table')->willReturn(true);
		$this->rowService->expects($this->never())->method('findInNode');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->getRow('tables', 3, '7')->getStatus());
	}

	public function testUpdateRowUsesTheResolvedRowId(): void {
		$this->rowService->method('resolveRowId')->with(self::UUID, 3, null)->willReturn(7);
		$this->rowService->expects($this->once())
			->method('updateSet')
			->with(7, null, [1 => 'value'], 'user1', 3)
			->willReturn($this->row(7));

		$response = $this->controller->updateRow('tables', 3, self::UUID, [1 => 'value']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateRowRejectsAnInvalidIdentifier(): void {
		$this->rowService->method('resolveRowId')->willThrowException(new BadRequestError('invalid'));
		$this->rowService->expects($this->never())->method('updateSet');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateRow('tables', 3, 'not-a-row', [])->getStatus());
	}

	public function testUpdateRowForwardsAnIntegerIdToAFederatedTable(): void {
		$table = new Table();
		$this->federationService->method('isNodeFederated')->willReturn(true);
		$this->tableService->method('find')->willReturn($table);
		$this->federationService->expects($this->once())
			->method('updateRow')
			->with($table, 7, [1 => 'value'])
			->willReturn(['id' => 7]);

		$this->assertSame(Http::STATUS_OK, $this->controller->updateRow('tables', 3, '7', [1 => 'value'])->getStatus());
	}

	public function testUpdateRowRejectsAUuidOnAFederatedTable(): void {
		$this->federationService->method('isNodeFederated')->willReturn(true);
		$this->tableService->method('find')->willReturn(new Table());
		$this->federationService->expects($this->never())->method('updateRow');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateRow('tables', 3, self::UUID, [])->getStatus());
	}

	public function testDeleteRowUsesTheResolvedRowId(): void {
		$this->rowService->method('resolveRowId')->with(self::UUID, null, 5)->willReturn(7);
		$this->rowService->expects($this->once())
			->method('delete')
			->with(7, 5, 'user1', null)
			->willReturn($this->row(7));

		$this->assertSame(Http::STATUS_OK, $this->controller->deleteRow('views', 5, self::UUID)->getStatus());
	}

	public function testDeleteRowRejectsAUuidOnAFederatedTable(): void {
		$this->federationService->method('isNodeFederated')->willReturn(true);
		$this->tableService->method('find')->willReturn(new Table());
		$this->federationService->expects($this->never())->method('deleteRow');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->deleteRow('tables', 3, self::UUID)->getStatus());
	}

	public function testShowContextRowResolvesTheIdentifierWithinTheView(): void {
		$this->contextNode(Application::NODE_TYPE_VIEW, 5);
		$this->rowService->expects($this->once())
			->method('resolveRowId')
			->with(self::UUID, null, 5)
			->willReturn(7);
		$this->rowService->expects($this->once())
			->method('findInNode')
			->with(7, null, 5, 'user1')
			->willReturn($this->row(7));

		$response = $this->controller->showContextRow('crm', 'clients', self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::UUID, $response->getData()['uuid']);
	}

	public function testShowContextRowRejectsAnInvalidIdentifier(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('resolveRowId')->willThrowException(new BadRequestError('invalid'));
		$this->rowService->expects($this->never())->method('findInNode');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->showContextRow('crm', 'clients', 'not-a-row')->getStatus());
	}

	public function testUpdateContextRowResolvesTheIdentifierWithinTheTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('resolveRowId')->with(self::UUID, 3, null)->willReturn(7);
		$this->rowService->expects($this->once())
			->method('updateSet')
			->with(7, null, [1 => 'value'], 'user1', 3)
			->willReturn($this->row(7));

		$this->assertSame(Http::STATUS_OK, $this->controller->updateContextRow('crm', 'clients', self::UUID, [1 => 'value'])->getStatus());
	}

	public function testDeleteContextRowDoesNotFindAUuidOfAnotherTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('resolveRowId')->with(self::UUID, 3, null)->willThrowException(new NotFoundError('Row not found.'));
		$this->rowService->expects($this->never())->method('delete');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->deleteContextRow('crm', 'clients', self::UUID)->getStatus());
	}

	public static function contextRouteProvider(): array {
		return [
			'index' => [static fn (RowOCSController $controller) => $controller->indexContextRows('crm', 'clients')],
			'show' => [static fn (RowOCSController $controller) => $controller->showContextRow('crm', 'clients', '7')],
			'create' => [static fn (RowOCSController $controller) => $controller->createContextRow('crm', 'clients', [1 => 'value'])],
			'update' => [static fn (RowOCSController $controller) => $controller->updateContextRow('crm', 'clients', '7', [1 => 'value'])],
			'delete' => [static fn (RowOCSController $controller) => $controller->deleteContextRow('crm', 'clients', '7')],
		];
	}

	public static function resolveNodeFailureProvider(): array {
		$cases = [];
		foreach (self::contextRouteProvider() as $route => [$call]) {
			$cases[$route . ', ambiguous context'] = [$call, new BadRequestError('ambiguous'), Http::STATUS_BAD_REQUEST];
			$cases[$route . ', unknown context or node'] = [$call, new NotFoundError('not found'), Http::STATUS_NOT_FOUND];
			$cases[$route . ', database failure'] = [$call, new \Exception('database'), Http::STATUS_INTERNAL_SERVER_ERROR];
		}
		return $cases;
	}

	/**
	 * @dataProvider resolveNodeFailureProvider
	 */
	public function testContextRouteReportsAFailedSlugLookupWithoutTouchingRows(\Closure $call, \Exception $failure, int $expectedStatus): void {
		$this->contextService->method('resolveNode')->with('crm', 'clients', 'user1')->willThrowException($failure);
		foreach (['resolveRowId', 'findAllByTable', 'findAllByView', 'findInNode', 'create', 'updateSet', 'delete'] as $method) {
			$this->rowService->expects($this->never())->method($method);
		}

		$this->assertSame($expectedStatus, $call($this->controller)->getStatus());
	}

	public function testIndexContextRowsListsTheRowsOfTheTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$rows = [$this->row(7)];
		$this->rowService->expects($this->once())
			->method('findAllByTable')
			->with(3, 'user1', 10, 20)
			->willReturn($rows);
		$this->rowService->expects($this->never())->method('findAllByView');
		$this->rowService->method('formatRows')->with($rows)->willReturn([['id' => 7]]);

		$response = $this->controller->indexContextRows('crm', 'clients', 10, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 7]], $response->getData());
	}

	public function testIndexContextRowsListsTheRowsOfTheView(): void {
		$this->contextNode(Application::NODE_TYPE_VIEW, 5);
		$this->rowService->expects($this->once())
			->method('findAllByView')
			->with(5, 'user1', null, null)
			->willReturn([]);
		$this->rowService->expects($this->never())->method('findAllByTable');
		$this->rowService->method('formatRows')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->indexContextRows('crm', 'clients')->getStatus());
	}

	public function testIndexContextRowsIsForbiddenWithoutReadAccessToTheNode(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('findAllByTable')->willThrowException(new PermissionError('no read access'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->indexContextRows('crm', 'clients')->getStatus());
	}

	public function testShowContextRowDoesNotFindANodeTheUserCannotAccess(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->deniedPermissions['canAccessNodeById'] = true;
		$this->rowService->expects($this->never())->method('resolveRowId');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->showContextRow('crm', 'clients', '7')->getStatus());
	}

	public function testCreateContextRowCreatesTheRowInTheTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->expects($this->once())
			->method('create')
			->with(3, null, $this->callback(static fn (RowDataInput $input) => $input->hasColumn(1) && $input[0]['value'] === 'value'))
			->willReturn($this->row(7));

		$response = $this->controller->createContextRow('crm', 'clients', ['1' => 'value']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(7, $response->getData()['id']);
	}

	public function testCreateContextRowCreatesTheRowInTheViewFromAJsonString(): void {
		$this->contextNode(Application::NODE_TYPE_VIEW, 5);
		$this->rowService->expects($this->once())
			->method('create')
			->with(null, 5, $this->callback(static fn (RowDataInput $input) => $input->hasColumn(2)))
			->willReturn($this->row(7));

		$this->assertSame(Http::STATUS_OK, $this->controller->createContextRow('crm', 'clients', '{"2": "value"}')->getStatus());
	}

	public function testCreateContextRowRejectsDataThatIsNoMap(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createContextRow('crm', 'clients', 'not json')->getStatus());
	}

	public function testCreateContextRowIsForbiddenWithoutCreateAccessToTheNode(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('create')->willThrowException(new PermissionError('no create access'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->createContextRow('crm', 'clients', [1 => 'value'])->getStatus());
	}

	public function testCreateContextRowForwardsToAFederatedTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$table = new Table();
		$this->federationService->method('isNodeFederated')->with(3, 'table')->willReturn(true);
		$this->tableService->method('find')->with(3, true)->willReturn($table);
		$this->federationService->expects($this->once())
			->method('createRow')
			->with($table, [1 => 'value'])
			->willReturn(['id' => 7]);
		$this->rowService->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_OK, $this->controller->createContextRow('crm', 'clients', [1 => 'value'])->getStatus());
	}

	public function testUpdateContextRowIsForbiddenWithoutUpdateAccessToTheNode(): void {
		$this->contextNode(Application::NODE_TYPE_VIEW, 5);
		$this->rowService->method('resolveRowId')->willReturn(7);
		$this->rowService->method('updateSet')->willThrowException(new PermissionError('no update access'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->updateContextRow('crm', 'clients', '7', [1 => 'value'])->getStatus());
	}

	public function testUpdateContextRowRejectsAUuidOnAFederatedTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->federationService->method('isNodeFederated')->willReturn(true);
		$this->tableService->method('find')->willReturn(new Table());
		$this->federationService->expects($this->never())->method('updateRow');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateContextRow('crm', 'clients', self::UUID, [])->getStatus());
	}

	public function testDeleteContextRowDeletesTheRowOfTheViewByItsId(): void {
		$this->contextNode(Application::NODE_TYPE_VIEW, 5);
		$this->rowService->method('resolveRowId')->with('7', null, 5)->willReturn(7);
		$this->rowService->expects($this->once())
			->method('delete')
			->with(7, 5, 'user1', null)
			->willReturn($this->row(7));

		$this->assertSame(Http::STATUS_OK, $this->controller->deleteContextRow('crm', 'clients', '7')->getStatus());
	}

	public function testDeleteContextRowIsForbiddenWithoutDeleteAccessToTheNode(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('resolveRowId')->willReturn(7);
		$this->rowService->method('delete')->willThrowException(new PermissionError('no delete access'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->deleteContextRow('crm', 'clients', '7')->getStatus());
	}

	public function testDeleteContextRowReportsAnInternalError(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->rowService->method('resolveRowId')->willReturn(7);
		$this->rowService->method('delete')->willThrowException(new InternalError('database'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller->deleteContextRow('crm', 'clients', '7')->getStatus());
	}

	public static function deniedContextRouteProvider(): array {
		return [
			'create on a table' => [Application::NODE_TYPE_TABLE, 'canCreateRowsById', static fn (RowOCSController $controller) => $controller->createContextRow('crm', 'clients', [1 => 'value'])],
			'update on a table' => [Application::NODE_TYPE_TABLE, 'canUpdateRowsByTableId', static fn (RowOCSController $controller) => $controller->updateContextRow('crm', 'clients', '7', [1 => 'value'])],
			'update on a view' => [Application::NODE_TYPE_VIEW, 'canUpdateRowsByViewId', static fn (RowOCSController $controller) => $controller->updateContextRow('crm', 'clients', '7', [1 => 'value'])],
			'delete on a table' => [Application::NODE_TYPE_TABLE, 'canDeleteRowsByTableId', static fn (RowOCSController $controller) => $controller->deleteContextRow('crm', 'clients', '7')],
			'delete on a view' => [Application::NODE_TYPE_VIEW, 'canDeleteRowsByViewId', static fn (RowOCSController $controller) => $controller->deleteContextRow('crm', 'clients', '7')],
		];
	}

	/**
	 * @dataProvider deniedContextRouteProvider
	 */
	public function testContextRouteIsForbiddenWithoutTheRowPermissionAlsoOnAFederatedNode(int $nodeType, string $deniedCheck, \Closure $call): void {
		$this->contextNode($nodeType, 3);
		$this->deniedPermissions[$deniedCheck] = true;
		$this->federationService->method('isNodeFederated')->willReturn(true);
		$this->tableService->method('find')->willReturn(new Table());
		$this->viewService->method('find')->willReturn(new View());
		foreach (['createRow', 'updateRow', 'deleteRow'] as $method) {
			$this->federationService->expects($this->never())->method($method);
		}
		foreach (['create', 'updateSet', 'delete'] as $method) {
			$this->rowService->expects($this->never())->method($method);
		}

		$this->assertSame(Http::STATUS_FORBIDDEN, $call($this->controller)->getStatus());
	}

	public function testContextRouteDoesNotFindANodeTheUserCannotAccess(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->deniedPermissions['canAccessNodeById'] = true;
		$this->rowService->expects($this->never())->method('findAllByTable');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->indexContextRows('crm', 'clients')->getStatus());
	}

	public function testIndexContextRowsForwardsToAFederatedTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$table = new Table();
		$this->federationService->method('isNodeFederated')->with(3, 'table')->willReturn(true);
		$this->tableService->method('find')->with(3, true)->willReturn($table);
		$this->federationService->expects($this->once())
			->method('getRows')
			->with($table, 10, 20)
			->willReturn([['id' => 7]]);
		$this->rowService->expects($this->never())->method('findAllByTable');

		$response = $this->controller->indexContextRows('crm', 'clients', 10, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 7]], $response->getData());
	}

	public function testIndexContextRowsForwardsToAFederatedView(): void {
		$this->contextNode(Application::NODE_TYPE_VIEW, 5);
		$view = new View();
		$this->federationService->method('isNodeFederated')->with(5, 'view')->willReturn(true);
		$this->viewService->method('find')->with(5, false, 'user1')->willReturn($view);
		$this->federationService->expects($this->once())->method('getRows')->with($view, null, null)->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->indexContextRows('crm', 'clients')->getStatus());
	}

	public function testShowContextRowRejectsAFederatedTable(): void {
		$this->contextNode(Application::NODE_TYPE_TABLE, 3);
		$this->federationService->method('isNodeFederated')->with(3, 'table')->willReturn(true);
		$this->rowService->expects($this->never())->method('findInNode');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->showContextRow('crm', 'clients', '7')->getStatus());
	}
}
