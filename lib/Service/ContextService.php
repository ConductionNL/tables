<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use InvalidArgumentException;
use OCA\Tables\AppInfo\Application;
use OCA\Tables\AuditLog\Operation;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\ContextNodeRelationMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContent;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Model\ColumnSettings;
use OCA\Tables\Model\ContextScheme;
use OCA\Tables\Model\FilterSet;
use OCA\Tables\Model\SortRuleSet;
use OCA\Tables\Model\ViewSettings;
use OCA\Tables\Service\Support\AuditLogServiceInterface;
use OCA\Tables\Validation\SlugValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class ContextService {

	public function __construct(
		private ContextMapper $contextMapper,
		private ContextNodeRelationMapper $contextNodeRelMapper,
		private PageMapper $pageMapper,
		private PageContentMapper $pageContentMapper,
		private LoggerInterface $logger,
		private PermissionsService $permissionsService,
		private IUserManager $userManager,
		private AuditLogServiceInterface $auditLogService,
		private IDBConnection $dbc,
		private ShareService $shareService,
		private bool $isCLI,
		protected INavigationManager $navigationManager,
		protected IURLGenerator $urlGenerator,
		private TableMapper $tableMapper,
		private ViewMapper $viewMapper,
	) {
	}

	use TTransactional;

	/**
	 * @return Context[]
	 * @throws Exception
	 * @throws InternalError
	 */
	public function findAll(?string $userId): array {
		if ($userId !== null && trim($userId) === '') {
			$userId = null;
		}
		if ($userId === null && !$this->isCLI) {
			$error = 'Try to set no user in context, but request is not allowed.';
			$this->logger->warning($error);
			throw new InternalError($error);
		}
		return $this->contextMapper->findAll($userId);
	}

	public function findForNavigation(string $userId): array {
		return $this->contextMapper->findForNavBar($userId);
	}

	public function addToNavigation(string $userId): void {
		$contexts = $this->findForNavigation($userId);
		foreach ($contexts as $context) {
			$iconRelPath = 'material/' . $context->getIcon() . '.svg';
			if (file_exists(__DIR__ . '/../../img/' . $iconRelPath)) {
				$iconUrl = $this->urlGenerator->imagePath(Application::APP_ID, $iconRelPath);
			} else {
				$iconUrl = $this->urlGenerator->imagePath('core', 'places/default-app-icon.svg');
			}

			$contextUrl = $this->urlGenerator->linkToRoute('tables.page.context', ['contextId' => $context->getId()]);

			$this->navigationManager->add([
				'id' => Application::APP_ID . '_application_' . $context->getId(),
				'name' => $context->getName(),
				'href' => $contextUrl,
				'icon' => $iconUrl,
				'order' => 500,
				'type' => 'link',
			]);
		}
	}

	/**
	 * @throws Exception
	 * @throws InternalError
	 * @throws NotFoundError
	 */
	public function findById(int $id, ?string $userId): Context {
		if ($userId !== null && trim($userId) === '') {
			$userId = null;
		}
		if ($userId === null && !$this->isCLI) {
			$error = 'Try to set no user in context, but request is not allowed.';
			$this->logger->warning($error);
			throw new InternalError($error);
		}

		return $this->contextMapper->findById($id, $userId);
	}

	/**
	 * @psalm-param list<array{id: int, type: int, permissions?: int, order?: int, slug?: string}> $nodes
	 * @param ?string $slug the name, unique among the owner's applications; application-{id} when null
	 * @throws Exception|PermissionError|InvalidArgumentException|BadRequestError
	 */
	public function create(string $name, string $iconName, string $description, array $nodes, string $ownerId, int $ownerType, ?string $slug = null): Context {
		if ($slug !== null) {
			$this->assertSlugValid($slug);
			if (isset($this->contextMapper->findSlugsForOwner($ownerType, $ownerId)[$slug])) {
				throw new BadRequestError('The slug ' . $slug . ' is already used by another application of the owner.');
			}
		}

		$context = new Context();
		$context->setName(trim($name));
		$context->setIcon(trim($iconName));
		$context->setDescription(trim($description));
		$context->setOwnerId($ownerId);
		$context->setOwnerType($ownerType);

		$this->atomic(function () use ($context, $nodes, $slug): void {
			$this->insertContext($context, $slug);

			if (!empty($nodes)) {
				$context->resetUpdatedFields();
				$this->insertNodesFromArray($context, $nodes);
			}
			$this->insertPage($context);
		}, $this->dbc);
		$this->auditLogService->log(Operation::ContextCreated, 'Tables application with ID %s was created for user %s', [
			'contextId' => $context->getId(),
			'ownerId' => $ownerId,
			'nodes' => array_map(static fn (array $node): array => ['nodeType' => (int)$node['type'], 'nodeId' => (int)$node['id']], $nodes),
		]);

		return $context;
	}

	/**
	 * @psalm-param list<array{id: int, type: int, permissions?: int, order?: int, slug?: string}> $nodes
	 * @throws Exception
	 * @throws DoesNotExistException
	 * @throws PermissionError|MultipleObjectsReturnedException|BadRequestError|InternalError
	 */
	public function update(int $contextId, string $userId, ?string $name, ?string $iconName, ?string $description, ?array $nodes, ?string $slug = null): Context {
		$context = $this->contextMapper->findById($contextId, $userId);

		if ($slug !== null && $slug !== $context->getSlug()) {
			$this->assertSlugsChangeable($context);
			$this->assertSlugValid($slug);
			if (isset($this->contextMapper->findSlugsForOwner($context->getOwnerType(), $context->getOwnerId())[$slug])) {
				throw new BadRequestError('The slug ' . $slug . ' is already used by another application of the owner.');
			}
			$context->setSlug($slug);
		}

		if ($name !== null) {
			$context->setName(trim($name));
		}
		if ($iconName !== null) {
			$context->setIcon(trim($iconName));
		}
		if ($description !== null) {
			$context->setDescription(trim($description));
		}

		$hasUpdatedNodeInformation = false;
		$addedNodes = [];
		$removedNodes = [];
		$updatedNodes = [];
		if ($nodes !== null) {
			$currentNodes = $context->getNodes();
			$currentPages = $context->getPages();

			$nodesBeingRemoved = [];
			$nodesBeingAdded = [];
			$nodesBeingKept = [];

			// new node relationships do not have an ID. We can recognize them
			// through their nodeType and nodeIds. For this we need to transform
			// the known relationships` keys to a compatible format.
			$oldNodeResolvableIdMapper = [];
			foreach ($currentNodes as $i => $oldNode) {
				$key = sprintf('t%di%d', $oldNode['node_type'], $oldNode['node_id']);
				$oldNodeResolvableIdMapper[$key] = $i;
			}

			foreach ($nodes as $node) {
				$key = sprintf('t%di%d', $node['type'], $node['id']);
				if (isset($oldNodeResolvableIdMapper[$key])) {
					$nodesBeingKept[$key] = $node;
					$currentNode = $currentNodes[$oldNodeResolvableIdMapper[$key]];
					$hasNewPermissions = $node['permissions'] !== $currentNode['permissions'];
					$hasNewSlug = isset($node['slug']) && $node['slug'] !== $currentNode['slug'];
					if ($hasNewPermissions || $hasNewSlug) {
						$nodeRel = $this->contextNodeRelMapper->findById($currentNode['id']);
						if ($hasNewPermissions) {
							$updatedNodes[] = [
								'nodeType' => $nodeRel->getNodeType(),
								'nodeId' => $nodeRel->getNodeId(),
								'previousPermissions' => $nodeRel->getPermissions(),
								'permissions' => (int)$node['permissions'],
							];
							$nodeRel->setPermissions($node['permissions']);
						}
						if ($hasNewSlug) {
							$this->assertSlugsChangeable($context);
							$this->assertNodeSlugAvailable($contextId, $node['slug']);
							$nodeRel->setSlug($node['slug']);
						}
						$this->updateNodeRelation($nodeRel);
						$currentNodes[$oldNodeResolvableIdMapper[$key]]['permissions'] = $nodeRel->getPermissions();
						$currentNodes[$oldNodeResolvableIdMapper[$key]]['slug'] = $nodeRel->getSlug();
						$hasUpdatedNodeInformation = true;
					}
					unset($oldNodeResolvableIdMapper[$key]);
					continue;
				}
				$nodesBeingAdded[$key] = $node;
			}

			foreach (array_diff_key($oldNodeResolvableIdMapper, $nodesBeingAdded, $nodesBeingKept) as $toRemoveId) {
				$nodesBeingRemoved[$toRemoveId] = $currentNodes[$toRemoveId];
			}
			unset($nodesBeingKept);

			$hasUpdatedNodeInformation = $hasUpdatedNodeInformation || !empty($nodesBeingAdded) || !empty($nodesBeingRemoved);

			foreach ($nodesBeingRemoved as $node) {
				$removedNodes[] = ['nodeType' => (int)$node['node_type'], 'nodeId' => (int)$node['node_id']];
				/** @var ContextNodeRelation $removedNode */
				/** @var PageContent[] $removedContents */
				[$removedNode, $removedContents] = $this->removeNodeFromContextAndPages($node['id']);
				foreach ($removedContents as $removedContent) {
					unset($currentPages[$removedContent->getPageId()]['content'][$removedContent->getId()]);
				}
				unset($currentNodes[$removedNode->getId()]);
			}
			unset($nodesBeingRemoved);

			foreach ($nodesBeingAdded as $node) {
				$nodeType = (int)($node['type']);
				$nodeId = (int)($node['id']);
				if (!$this->permissionsService->canManageNodeById($nodeType, $nodeId, $userId)) {
					throw new PermissionError(sprintf('Owner cannot manage node %d (type %d)', $nodeId, $nodeType));
				}
				$addedNodes[] = ['nodeType' => $nodeType, 'nodeId' => $nodeId];

				/** @var ContextNodeRelation $addedNode */
				/** @var PageContent $updatedContent */
				[$addedNode, $updatedContent] = $this->addNodeToContextAndStartpage(
					$contextId,
					$node['id'],
					$node['type'],
					$node['permissions'],
					$node['order'] ?? 100,
					$userId,
					$node['slug'] ?? null,
				);
				$currentNodes[$addedNode->getId()] = $addedNode->jsonSerialize();
				$currentPages[$updatedContent->getPageId()]['content'][$updatedContent->getId()] = $updatedContent->jsonSerialize();
			}
			unset($nodesBeingAdded);

			// Update order of nodes
			$startPageId = null;
			foreach ($currentPages as $page) {
				if ($page['page_type'] === Page::TYPE_STARTPAGE) {
					$startPageId = $page['id'];
					break;
				}
			}

			if ($startPageId !== null && isset($currentPages[$startPageId]['content'])) {
				$nodeTypeIdToRelId = [];
				foreach ($currentNodes as $relId => $nodeData) {
					$key = sprintf('t%di%d', $nodeData['node_type'], $nodeData['node_id']);
					$nodeTypeIdToRelId[$key] = $relId;
				}

				$relIdToContentId = [];
				foreach ($currentPages[$startPageId]['content'] as $contentId => $content) {
					$relIdToContentId[$content['node_rel_id']] = $contentId;
				}

				$contentsToUpdate = [];
				foreach ($nodes as $index => $node) {
					$key = sprintf('t%di%d', $node['type'], $node['id']);
					if (isset($nodeTypeIdToRelId[$key])) {
						$relId = $nodeTypeIdToRelId[$key];
						if (isset($relIdToContentId[$relId])) {
							$contentId = $relIdToContentId[$relId];
							$newOrder = ($index + 1) * 10;

							// Optimistic check: if order is already correct in memory, we might skip?
							// However, simpler to just add to update list, updateContentOrder should handle exceptions.
							// But to avoid DB writes if nothing changed:
							if ($currentPages[$startPageId]['content'][$contentId]['order'] !== $newOrder) {
								$contentsToUpdate[] = [
									'id' => $contentId,
									'order' => $newOrder
								];
								$currentPages[$startPageId]['content'][$contentId]['order'] = $newOrder;
								$hasUpdatedNodeInformation = true;
							}
						}
					}
				}

				if (!empty($contentsToUpdate)) {
					$this->updateContentOrder($startPageId, $contentsToUpdate);
				}
			}
		}

		try {
			$context = $this->contextMapper->update($context);
		} catch (Exception $e) {
			throw $this->toSlugConflict($e, 'The slug is already used by another application of the owner.');
		}
		if (isset($currentNodes, $currentPages) && $hasUpdatedNodeInformation) {
			$context->setNodes($currentNodes);
			$context->setPages($currentPages);
		}
		$this->auditLogService->log(Operation::ContextUpdated, 'Tables application with ID %s was updated', [
			'contextId' => $contextId,
			'addedNodes' => $addedNodes,
			'removedNodes' => $removedNodes,
			'updatedNodes' => $updatedNodes,
		]);

		return $context;
	}

	/**
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function delete(int $contextId, string $userId): Context {
		$context = $this->contextMapper->findById($contextId, $userId);

		$this->atomic(function () use ($context): void {
			$this->shareService->deleteAllForContext($context);
			$this->contextNodeRelMapper->deleteAllByContextId($context->getId());
			$pageIds = $this->pageMapper->getPageIdsForContext($context->getId());
			foreach ($pageIds as $pageId) {
				$this->pageContentMapper->deleteByPageId($pageId);
				$this->pageMapper->deleteByPageId($pageId);
			}
			$this->contextMapper->delete($context);
		}, $this->dbc);
		$this->auditLogService->log(Operation::ContextDeleted, 'Tables application with ID %s was deleted', [
			'contextId' => $context->getId(),
			'ownerId' => $context->getOwnerId(),
		]);
		return $context;
	}

	public function deleteNodeRel(int $nodeId, int $nodeType): void {
		try {
			$nodeRelIds = $this->contextNodeRelMapper->getRelIdsForNode($nodeId, $nodeType);
			$this->atomic(function () use ($nodeRelIds): void {
				$this->pageContentMapper->deleteByNodeRelIds($nodeRelIds);
				$this->contextNodeRelMapper->deleteByNodeRelIds($nodeRelIds);
			}, $this->dbc);
		} catch (Exception $e) {
			$this->logger->error('Something went wrong while deleting node relation for node id: ' . (string)$nodeId . ' and node type ' . (string)$nodeType, ['exception' => $e]);
		}
	}

	/**
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws BadRequestError
	 */
	public function transfer(int $contextId, string $newOwnerId, int $newOwnerType): Context {
		$context = $this->contextMapper->findById($contextId);

		// the owner type check can be dropped as soon as NC 29 is the lowest supported version,
		// as the int range as defined in the Controller will be enforced by the Http/Dispatcher.
		if ($newOwnerType !== Application::OWNER_TYPE_USER) {
			throw new BadRequestError('Unsupported owner type');
		}

		if (!$this->userManager->userExists($newOwnerId)) {
			throw new BadRequestError('User does not exist');
		}

		$isOwnerChanging = $newOwnerId !== $context->getOwnerId() || $newOwnerType !== $context->getOwnerType();
		if ($isOwnerChanging && isset($this->contextMapper->findSlugsForOwner($newOwnerType, $newOwnerId)[$context->getSlug()])) {
			throw new BadRequestError('The new owner already has an application with the slug ' . $context->getSlug() . '.');
		}

		$oldOwnerId = $context->getOwnerId();
		$context->setOwnerId($newOwnerId);
		$context->setOwnerType($newOwnerType);

		try {
			$context = $this->atomic(function () use ($context, $contextId, $newOwnerId, $oldOwnerId) {
				$context = $this->contextMapper->update($context);
				$this->shareService->transferSharesForContext($contextId, $newOwnerId, $oldOwnerId);
				return $context;
			}, $this->dbc);
		} catch (\Exception $e) {
			$this->logger->error('Failed to transfer context ownership: ' . $e->getMessage(), ['exception' => $e]);
			throw new InternalError('Failed to transfer context ownership: ' . $e->getMessage());
		}

		$this->auditLogService->log(Operation::ContextTransferred, 'Tables application with ID %s was transferred to user %s', [
			'contextId' => $contextId,
			'toUserId' => $newOwnerId,
			'fromUserId' => $oldOwnerId,
		]);

		return $context;
	}

	/**
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function addNodeToContextById(int $contextId, int $nodeId, int $nodeType, int $permissions, ?string $userId, ?string $slug = null): ContextNodeRelation {
		$context = $this->contextMapper->findById($contextId, $userId);
		return $this->addNodeToContext($context, $nodeId, $nodeType, $permissions, $slug);
	}

	/**
	 * @throws Exception
	 */
	public function removeNodeFromContext(ContextNodeRelation $nodeRelation): ContextNodeRelation {
		return $this->contextNodeRelMapper->delete($nodeRelation);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function removeNodeFromContextById(int $nodeRelationId): ContextNodeRelation {
		$nodeRelation = $this->contextNodeRelMapper->findById($nodeRelationId);
		return $this->contextNodeRelMapper->delete($nodeRelation);
	}

	/**
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function addNodeToContextAndStartpage(int $contextId, int $nodeId, int $nodeType, int $permissions, int $order, string $userId, ?string $slug = null): array {
		$relation = $this->addNodeToContextById($contextId, $nodeId, $nodeType, $permissions, $userId, $slug);
		$pageContent = $this->addNodeRelToPage($relation, $order);
		return [$relation, $pageContent];
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function removeNodeFromContextAndPages(int $nodeRelationId): array {
		$nodeRelation = $this->removeNodeFromContextById($nodeRelationId);
		$contents = $this->removeNodeRelFromAllPages($nodeRelation);
		return [$nodeRelation, $contents];
	}

	/**
	 * @param ?string $slug the name, unique within the application; table-{id} or view-{id} when null
	 * @throws Exception
	 * @throws BadRequestError
	 */
	public function addNodeToContext(Context $context, int $nodeId, int $nodeType, int $permissions, ?string $slug = null): ContextNodeRelation {
		if ($slug === null) {
			$slug = self::uniqueSlug(
				self::defaultNodeSlug($nodeType, $nodeId),
				$this->contextNodeRelMapper->findSlugsByContextId($context->getId()),
			);
		} else {
			$this->assertNodeSlugAvailable($context->getId(), $slug);
		}

		$contextNodeRel = new ContextNodeRelation();
		$contextNodeRel->setContextId($context->getId());
		$contextNodeRel->setNodeId($nodeId);
		$contextNodeRel->setNodeType($nodeType);
		$contextNodeRel->setPermissions($permissions);
		$contextNodeRel->setSlug($slug);

		try {
			return $this->contextNodeRelMapper->insert($contextNodeRel);
		} catch (Exception $e) {
			throw $this->toSlugConflict($e, 'The slug ' . $slug . ' is already used in this application.');
		}
	}

	/**
	 * Finds the table or view that an application, both given by slug,
	 * calls by the given slug.
	 *
	 * @throws BadRequestError when more than one application the user can access has the slug
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function resolveNode(string $contextSlug, string $nodeSlug, string $userId): ContextNodeRelation {
		$contexts = $this->contextMapper->findAllBySlug($contextSlug, $userId);
		if ($contexts === []) {
			throw new NotFoundError('No application with the slug ' . $contextSlug . ' found.');
		}
		if (count($contexts) > 1) {
			throw new BadRequestError('The slug ' . $contextSlug . ' matches more than one application you can access.');
		}

		try {
			return $this->contextNodeRelMapper->findByContextAndSlug($contexts[0]->getId(), $nodeSlug);
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			throw new NotFoundError('The application ' . $contextSlug . ' has no table or view with the slug ' . $nodeSlug . '.', 0, $e);
		}
	}

	public function addNodeRelToPage(ContextNodeRelation $nodeRel, ?int $order = null, ?int $pageId = null): PageContent {
		if ($pageId === null) {
			// when no page is given, find the startpage to add it to
			$context = $this->contextMapper->findById($nodeRel->getContextId());
			$pages = $context->getPages();
			foreach ($pages as $page) {
				if ($page['page_type'] === 'startpage') {
					$pageId = $page['id'];
					break;
				}
			}
		}

		$pageContent = $this->pageContentMapper->findByPageAndNodeRelation($pageId, $nodeRel->getId());

		if ($pageContent === null) {
			$pageContent = new PageContent();
			$pageContent->setPageId($pageId);
			$pageContent->setNodeRelId($nodeRel->getId());
			$pageContent->setOrder($order ?? 100); //FIXME: demand or calc order

			$pageContent = $this->pageContentMapper->insert($pageContent);
		}
		return $pageContent;
	}

	public function removeNodeRelFromAllPages(ContextNodeRelation $nodeRelation): array {
		$contents = $this->pageContentMapper->findByNodeRelation($nodeRelation->getId());
		/** @var PageContent $content */
		foreach ($contents as $content) {
			try {
				$this->pageContentMapper->delete($content);
			} catch (Exception $e) {
				$this->logger->warning('Failed to delete Contexts page content with ID {pcId}', [
					'pcId' => $content->getId(),
					'exception' => $e,
				]);
			}
		}
		return $contents;
	}

	public function updateContentOrder(int $pageId, array $contents): array {
		$updated = [];
		foreach ($contents as $content) {
			try {
				$updated[] = $this->updatePageContent($pageId, $content['id'], $content['order']);
			} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception|InvalidArgumentException $e) {
				$this->logger->info('Could not updated order of content with ID {cID}', [
					'cID' => $content['id'],
					'exception' => $e,
				]);
			}
		}
		return $updated;
	}

	/**
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws InvalidArgumentException
	 */
	protected function updatePageContent(int $pageId, int $contentId, int $order): PageContent {
		$pageContent = $this->pageContentMapper->findById($contentId);
		if ($pageContent->getPageId() !== $pageId) {
			throw new InvalidArgumentException('Content does not belong to given page');
		}
		$pageContent->setOrder($order);
		return $this->pageContentMapper->update($pageContent);
	}

	/**
	 * Inserts the context under the given slug, or under
	 * application-{id} when it is null. The id only exists after the insert, so the
	 * row first gets a random name that no client can choose.
	 *
	 * @throws Exception
	 */
	protected function insertContext(Context $context, ?string $slug): void {
		if ($slug !== null) {
			$context->setSlug($slug);
			$this->contextMapper->insert($context);
			return;
		}

		$context->setSlug('_' . bin2hex(random_bytes(16)));
		$this->contextMapper->insert($context);
		$context->setSlug(self::uniqueSlug(
			'application-' . $context->getId(),
			$this->contextMapper->findSlugsForOwner($context->getOwnerType(), $context->getOwnerId()),
		));
		$this->contextMapper->update($context);
	}

	/**
	 * @throws BadRequestError
	 */
	private function assertSlugValid(string $slug): void {
		if (SlugValidator::isTooLong($slug)) {
			throw new BadRequestError('Slug must not exceed ' . SlugValidator::MAX_LENGTH . ' characters.');
		}
		if (!SlugValidator::hasValidCharacters($slug)) {
			throw new BadRequestError('Slug must start with a lowercase letter and contain only lowercase letters and numbers, in groups separated by single dashes.');
		}
	}

	/**
	 * Slugs of a shared application are fixed, so the routes others use keep working.
	 *
	 * @throws BadRequestError
	 * @throws InternalError
	 */
	private function assertSlugsChangeable(Context $context): void {
		if ($this->shareService->isContextShared($context)) {
			throw new BadRequestError('The slugs of a shared application cannot be changed.');
		}
	}

	/**
	 * @throws BadRequestError
	 * @throws Exception
	 */
	private function assertNodeSlugAvailable(int $contextId, string $slug): void {
		$this->assertSlugValid($slug);
		if (isset($this->contextNodeRelMapper->findSlugsByContextId($contextId)[$slug])) {
			throw new BadRequestError('The slug ' . $slug . ' is already used in this application.');
		}
	}

	/**
	 * @throws Exception
	 * @throws BadRequestError
	 */
	private function updateNodeRelation(ContextNodeRelation $nodeRel): void {
		try {
			$this->contextNodeRelMapper->update($nodeRel);
		} catch (Exception $e) {
			throw $this->toSlugConflict($e, 'The slug ' . $nodeRel->getSlug() . ' is already used in this application.');
		}
	}

	/**
	 * A unique constraint violation means another request took the slug
	 * after it was checked.
	 */
	private function toSlugConflict(Exception $e, string $message): BadRequestError|Exception {
		if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return new BadRequestError($message, 0, $e);
		}
		return $e;
	}

	public static function defaultNodeSlug(int $nodeType, int $nodeId): string {
		return ($nodeType === Application::NODE_TYPE_VIEW ? 'view-' : 'table-') . $nodeId;
	}

	/**
	 * The base slug, or the base with the lowest free suffix -2, -3, ...
	 *
	 * @param array<string, true> $taken
	 */
	public static function uniqueSlug(string $base, array $taken): string {
		$slug = $base;
		for ($suffix = 2; isset($taken[$slug]); $suffix++) {
			$slug = $base . '-' . $suffix;
		}
		return $slug;
	}

	/**
	 * The requested name when it is valid and free, else null.
	 *
	 * @param array<string, true> $taken
	 */
	private static function availableSlug(mixed $requested, array $taken): ?string {
		if (!is_string($requested) || !SlugValidator::isValid($requested) || isset($taken[$requested])) {
			return null;
		}
		return $requested;
	}

	protected function insertPage(Context $context): void {
		$page = new Page();
		$page->setContextId($context->getId());
		$page->setPageType(Page::TYPE_STARTPAGE);
		$this->pageMapper->insert($page);

		$addedPage = $page->jsonSerialize();

		$i = 1;
		$contextNodes = $context->getNodes();
		if ($contextNodes) {
			foreach ($contextNodes as $node) {
				$pageContent = new PageContent();
				$pageContent->setPageId($page->getId());
				$pageContent->setNodeRelId($node['id']);
				$pageContent->setOrder(10 * $i++);

				$this->pageContentMapper->insert($pageContent);

				$addedPage['content'][$pageContent->getId()] = $pageContent->jsonSerialize();
				// the content is already embedded in the page
				unset($addedPage['content'][$pageContent->getId()]['pageId']);
			}
		}

		$context->setPages([$addedPage['id'] => $addedPage]);
	}

	/**
	 * @psalm-param list<array{id: int, type: int, permissions?: int, order?: int, slug?: string}> $nodes
	 * @throws PermissionError|InvalidArgumentException|BadRequestError
	 */
	protected function insertNodesFromArray(Context $context, array $nodes): void {
		$addedNodes = [];

		$userId = $context->getOwnerType() === Application::OWNER_TYPE_USER ? $context->getOwnerId() : null;
		foreach ($nodes as $node) {
			try {
				$nodeType = (int)($node['type']);
				$nodeId = (int)($node['id']);

				if (!$this->permissionsService->canManageNodeById($nodeType, $nodeId, $userId)) {
					throw new PermissionError(sprintf('Owner cannot manage node %d (type %d)', $nodeId, $nodeType));
				}
				$contextNodeRel = $this->addNodeToContext($context, $nodeId, $nodeType, $node['permissions'] ?? Application::PERMISSION_READ, $node['slug'] ?? null);
				$addedNodes[] = $contextNodeRel->jsonSerialize();
			} catch (Exception $e) {
				$this->logger->warning('Could not add node {ntype}/{nid} to context {cid}, skipping.', [
					'app' => Application::APP_ID,
					'ntype' => $node['type'],
					'nid' => $node['id'],
					'permissions' => $node['permissions'] ?? '',
					'cid' => $context['id'],
					'exception' => $e,
				]);
			}
		}
		$context->setNodes($addedNodes);
	}

	/**
	 * @param Table $table
	 * @param array $context
	 * @param int $tableOldId
	 *
	 * @return Context|null
	 *
	 * @throws \Exception
	 */
	public function importContext(Table $table, array $context, int $tableOldId): ?Context {
		// checking if any node in $context['nodes'] matches the old table id
		$hasMatchingNode = false;
		if (!empty($context['nodes'])) {
			foreach ($context['nodes'] as $nodeArr) {
				if (($nodeArr['node_id'] ?? null) == $tableOldId) {
					$hasMatchingNode = true;
					break;
				}
			}
		}

		if (!$hasMatchingNode) {
			return null;
		}

		$newContext = new Context();
		$newContext->setName($context['name'] ?? '');
		$newContext->setIcon($context['iconName'] ?? '');
		$newContext->setDescription($context['description'] ?? '');
		$newContext->setOwnerId($table->getOwnership() ?? '');
		$newContext->setOwnerType($context['ownerType'] ?? 0);
		$this->insertContext($newContext, self::availableSlug(
			$context['slug'] ?? null,
			$this->contextMapper->findSlugsForOwner($newContext->getOwnerType(), $newContext->getOwnerId()),
		));

		// insert nodes
		$nodeRelMap = [];
		if (!empty($context['nodes'])) {
			$takenNodeNames = [];
			foreach ($context['nodes'] as $nodeArr) {
				$nodeType = (int)($nodeArr['node_type'] ?? $nodeArr['nodeType'] ?? 0);
				$slug = self::availableSlug($nodeArr['slug'] ?? $nodeArr['slug'] ?? null, $takenNodeNames)
					?? self::uniqueSlug(self::defaultNodeSlug($nodeType, $table->getId()), $takenNodeNames);
				$takenNodeNames[$slug] = true;

				$contextNodeRel = new ContextNodeRelation();
				$contextNodeRel->setContextId($newContext->getId());
				$contextNodeRel->setNodeId($table->getId());
				$contextNodeRel->setNodeType($nodeType);
				$contextNodeRel->setPermissions($nodeArr['permissions'] ?? 0);
				$contextNodeRel->setSlug($slug);
				$contextNodeRel = $this->contextNodeRelMapper->insert($contextNodeRel);
				$nodeRelMap[$nodeArr['id'] ?? null] = $contextNodeRel;
			}
		}

		// insert pages
		$pageMap = [];
		if (!empty($context['pages'])) {
			foreach ($context['pages'] as $pageArr) {
				$page = new Page();
				$page->setContextId($newContext->getId());
				$page->setPageType($pageArr['page_type'] ?? $pageArr['pageType'] ?? '');
				$page = $this->pageMapper->insert($page);
				$pageMap[$pageArr['id'] ?? null] = $page;

				// insert page conten
				if (!empty($pageArr['content'])) {
					foreach ($pageArr['content'] as $contentArr) {
						$pageContent = new PageContent();
						$pageContent->setPageId($page->getId());
						$oldNodeRelId = $contentArr['node_rel_id'] ?? $contentArr['nodeRelId'] ?? null;
						$newNodeRel = $nodeRelMap[$oldNodeRelId] ?? null;
						$pageContent->setNodeRelId($newNodeRel ? $newNodeRel->getId() : null);
						$pageContent->setOrder($contentArr['order'] ?? 100);
						$this->pageContentMapper->insert($pageContent);
					}
				}
			}
		}

		return $newContext;
	}

	/**
	 * @param int $contextId
	 *
	 * @return ContextScheme
	 *
	 * @throws ContainerExceptionInterface
	 * @throws Exception
	 * @throws NotFoundError
	 * @throws NotFoundExceptionInterface
	 */
	public function getScheme(int $contextId, string $userId): ContextScheme {
		$context = $this->contextMapper->findById($contextId);
		$tableService = \OCP\Server::get(TableService::class);
		$viewService = \OCP\Server::get(ViewService::class);
		$nodes = $context->getNodes();
		$tables = [];

		foreach ($nodes as &$node) {
			if ($node['node_type'] === Application::NODE_TYPE_TABLE) {
				try {
					$table = $tableService->find($node['node_id']);
					$tableScheme = $tableService->getScheme($node['node_id'], $userId)->jsonSerialize();
					$tables[$node['node_id']] = $tableScheme;
					$node['node_uuid'] = $table->getUuid();
					$node['node_title'] = $table->getTitle();
				} catch (InternalError|PermissionError|NotFoundError $e) {
					$this->logger->error('Failed to enhance context scheme for table node: ' . $e->getMessage(), ['exception' => $e]);
				}
			}
			if ($node['node_type'] === Application::NODE_TYPE_VIEW) {
				try {
					$view = $viewService->find($node['node_id']);
					$node['node_uuid'] = $view->getUuid();
					$node['node_title'] = $view->getTitle();
					if (isset($tables[$view->getTableId()])) {
						continue;
					}
					$tables[$view->getTableId()] = $tableService->getScheme($view->getTableId(), $userId)->jsonSerialize();
				} catch (InternalError|PermissionError|NotFoundError $e) {
					$this->logger->error('Failed to enhance context scheme for view node: ' . $e->getMessage(), ['exception' => $e]);
				}
			}
		}

		return new ContextScheme(
			$context->getName(),
			$context->getIcon(),
			$context->getDescription(),
			array_values($nodes),
			$context->getPages(),
			array_values($tables),
		);
	}

	/**
	 * @param int $contextId
	 * @param array $updateScheme
	 *
	 * @return array|array[]
	 *
	 * @throws Exception
	 * @throws InternalError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws BadRequestError
	 */
	public function compareSchemeChanges(int $contextId, array $updateScheme, string $userId): array {
		$context = $this->contextMapper->findById($contextId);
		$currentScheme = $this->getScheme($contextId, $userId);
		$tableService = \OCP\Server::get(TableService::class);

		$changes = [
			'name' => [
				'from' => $context->getName(),
				'to' => $updateScheme['name'] ?? $context->getName()
			],
			'icon' => [
				'from' => $context->getIcon(),
				'to' => $updateScheme['icon'] ?? $context->getIcon()
			],
			'description' => [
				'from' => $context->getDescription(),
				'to' => $updateScheme['description'] ?? $context->getDescription()
			],
			'nodes' => [
				'from' => $currentScheme->getNodes(),
				'to' => $updateScheme['nodes'] ?? $currentScheme->getNodes()
			],
		];

		$tables = $tableService->findAll();
		$tablesMap = [];
		foreach ($tables as $table) {
			$tablesMap[$table->getUuid()] = $table;
		}

		$updateSchemeTables = $updateScheme['tables'] ?? [];
		$changes['addTables'] = [];
		$changes['modifyTables'] = [];

		// Detect new tables
		foreach ($updateSchemeTables as $table) {
			if (!isset($tablesMap[$table['uuid']])) {
				$changes['addTables'][] = $table;
			}
		}

		// Detect modify tables
		foreach ($updateSchemeTables as $tableUpdateScheme) {
			if (isset($tablesMap[$tableUpdateScheme['uuid']])) {
				$tableLocalId = $tablesMap[$tableUpdateScheme['uuid']]->getId();
				$tableChanges = $tableService->compareTableSchemeChanges($tableLocalId, $tableUpdateScheme);
				if ($tableChanges['hasChanges']) {
					$changes['modifyTables'][$tableUpdateScheme['uuid']] = $tableChanges;
				}
			}
		}

		return $changes;
	}

	/**
	 * @param int $contextId
	 * @param string $name
	 * @param string $iconName
	 * @param string $description
	 * @param array $nodes
	 * @param array $tables
	 * @param string $userId
	 *
	 * @return Context
	 *
	 * @throws BadRequestError
	 * @throws ContainerExceptionInterface
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws InternalError
	 * @throws MultipleObjectsReturnedException
	 * @throws NotFoundError
	 * @throws NotFoundExceptionInterface
	 * @throws PermissionError
	 */
	public function importScheme(int $contextId, string $name, string $iconName, string $description, array $nodes, array $tables, string $userId): Context {
		// Validate the structure of the columns and views arrays
		if (!isset($tables['addTables']) || !is_array($tables['addTables'])
			|| !isset($tables['modifyTables']) || !is_array($tables['modifyTables'])) {
			throw new BadRequestError('Invalid tables structure provided.');
		}

		$tableService = \OCP\Server::get(TableService::class);
		$viewService = \OCP\Server::get(ViewService::class);
		$columnService = \OCP\Server::get(ColumnService::class);

		foreach ($tables['addTables'] as $tableScheme) {
			$tableService->validateTableScheme($tableScheme);
			$table = $tableService->importTable($tableScheme, $userId);
			foreach ($tableScheme['columns'] as $columnData) {
				$columnService->importColumn($table, $columnData);
			}
			$newColumns = $columnService->findAllByTable($table->getId(), $userId, $table);
			$columnsMap = [];
			foreach ($newColumns as $column) {
				$columnsMap[$column->getUuid()] = $column;
			}
			foreach ($tableScheme['views'] as $viewData) {
				$viewData['viewSettings'] = ViewSettings::createFromInputArray($viewData['viewSettings'] ?? [], $columnsMap)->jsonSerialize();
				$viewData['columnSettings'] = ColumnSettings::createViewSettingsFromInputArray($viewData['columnSettings'], $columnsMap)->jsonSerialize();
				$viewData['sort'] = SortRuleSet::createFromInputArray($viewData['sort'], $columnsMap)->jsonSerialize();
				$viewData['filter'] = FilterSet::createFromInputArray($viewData['filter'], $columnsMap)->jsonSerialize();
				$viewService->importView($table->getId(), $viewData, $userId);
			}
		}

		foreach ($tables['modifyTables'] as $tableScheme) {
			if (!isset($tableScheme['uuid'])) {
				throw new BadRequestError('Table scheme must include a UUID for modification.');
			}
			$tableService->validateTableScheme($tableScheme);
			$table = $this->tableMapper->findByUuid($tableScheme['uuid']);
			$tableService->updateTableStructure($table->getId(), $tableScheme['columns'], $tableScheme['views'], $tableScheme['columnOrder'], $tableScheme['sort'], $userId);
		}

		// Resolve node ids for the new tables and views
		$resolvedNodes = [];
		foreach ($nodes as $node) {
			if ($node['node_type'] === Application::NODE_TYPE_TABLE) {
				$table = $this->tableMapper->findByUuid($node['node_uuid']);
				$resolvedNodes[] = [
					'id' => $table->getId(),
					'type' => $node['node_type'],
					'permissions' => $node['permissions'],
				];
			} elseif ($node['node_type'] === Application::NODE_TYPE_VIEW) {
				$view = $this->viewMapper->findByUuid($node['node_uuid']);
				$resolvedNodes[] = [
					'id' => $view->getId(),
					'type' => $node['node_type'],
					'permissions' => $node['permissions'],
				];
			}
		}

		$context = $this->update($contextId, $userId, $name, $iconName, $description, $resolvedNodes);

		return $context;
	}
}
