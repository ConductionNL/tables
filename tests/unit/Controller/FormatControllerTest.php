<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Controller;

use OCA\Tables\Controller\FormatController;
use OCA\Tables\Db\Format;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Service\FormatService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class FormatControllerTest extends TestCase {
	/**
	 * Nextcloud lets only administrators call controller methods without NoAdminRequired.
	 *
	 * @dataProvider methodAccess
	 */
	public function testOnlyReadingIsOpenToEveryone(string $method, bool $isOpenToEveryone): void {
		$attributes = (new ReflectionMethod(FormatController::class, $method))->getAttributes(NoAdminRequired::class);

		$this->assertSame($isOpenToEveryone, $attributes !== []);
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function methodAccess(): array {
		return [
			'index' => ['index', true],
			'show' => ['show', true],
			'create' => ['create', false],
			'update' => ['update', false],
			'destroy' => ['destroy', false],
		];
	}

	public function testOnlyAdministratorsListDisabledFormats(): void {
		$formatService = $this->createMock(FormatService::class);
		$formatService->expects($this->exactly(2))->method('findAll')
			->willReturnCallback(fn (bool $includeDisabled): array => $includeDisabled ? [$this->format(), $this->format()] : [$this->format()]);

		$this->assertCount(1, $this->createController($formatService, false)->index()->getData());
		$this->assertCount(2, $this->createController($formatService, true)->index()->getData());
	}

	public function testAnInvalidDefinitionIsABadRequest(): void {
		$formatService = $this->createMock(FormatService::class);
		$formatService->method('create')->willThrowException(new BadRequestError('A format named email already exists.'));

		$response = $this->createController($formatService, true)->create('email', 'Email', 'text', ['match' => [['var' => 'value'], '@']]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'A format named email already exists.'], $response->getData());
	}

	private function format(): Format {
		$format = new Format();
		$format->setRule('{"match":[{"var":"value"},"@"]}');
		return $format;
	}

	private function createController(FormatService $formatService, bool $isAdmin): FormatController {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		return new FormatController(
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			'alice',
			$formatService,
			$groupManager,
		);
	}
}
