<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\Row2;
use OCA\Tables\Db\Row2Mapper;
use OCA\Tables\Db\RowSleeveMapper;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\Model\RowObjectInput;
use OCA\Tables\Service\RowObjectInputConverter;
use OCA\Tables\Service\RowObjectSerializer;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RowObjectSerializerTest extends TestCase {
	private const ROW_UUID = '0199f1a0-7c3e-7d2a-9b1e-4f5a6b7c8d9e';
	private const TARGET_UUID = '0199f1a0-0000-7000-8000-000000000042';

	private RowSleeveMapper&MockObject $rowSleeveMapper;
	private LoggerInterface&MockObject $logger;
	private InstanceTimezone $instanceTimezone;
	private RowObjectSerializer $serializer;
	/** @var array<int, Column> */
	private array $columns = [];

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('default_timezone', 'UTC')->willReturn('Europe/Amsterdam');
		$this->instanceTimezone = new InstanceTimezone($config);

		$this->columns = [
			1 => $this->column(1, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, 'name'),
			2 => $this->column(2, Column::TYPE_DATETIME, '', 'meeting'),
			3 => $this->column(3, Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_DATE, 'birthday'),
			4 => $this->column(4, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, 'website'),
			5 => $this->column(5, Column::TYPE_RELATION, '', 'project'),
			6 => $this->column(6, Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK, 'is_active'),
			7 => $this->column(7, Column::TYPE_NUMBER, '', 'age'),
			8 => $this->column(8, Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_TIME, 'starts_at'),
		];
		$this->rowSleeveMapper = $this->createMock(RowSleeveMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->serializer = new RowObjectSerializer($this->rowSleeveMapper, $this->instanceTimezone, $this->logger);
	}

	private function column(int $id, string $type, string $subtype, string $technicalName): Column {
		$column = new Column();
		$column->setId($id);
		$column->setType($type);
		$column->setSubtype($subtype);
		$column->setTechnicalName($technicalName);
		return $column;
	}

	/**
	 * A row read with the given columns (all columns by default), holding cells for the given values.
	 *
	 * @param array<int, mixed> $valuesByColumnId
	 * @param int[]|null $columnIds
	 */
	private function row(array $valuesByColumnId, ?array $columnIds = null): Row2 {
		$row = new Row2();
		$row->setId(12);
		$row->setUuid(self::ROW_UUID);
		$row->setTableId(3);
		$row->setCreatedBy('alice');
		$row->setCreatedAt('2026-10-16 12:30:00');
		$row->setLastEditBy('bob');
		$row->setLastEditAt('2026-01-16 08:00:00');
		$columnsByAlias = [];
		foreach ($columnIds ?? array_keys($this->columns) as $columnId) {
			$columnsByAlias[$this->columns[$columnId]->getTechnicalName()] = $this->columns[$columnId];
		}
		$row->setColumnsByAlias($columnsByAlias);
		$dataByAlias = [];
		foreach ($valuesByColumnId as $columnId => $value) {
			$dataByAlias[$this->columns[$columnId]->getTechnicalName()] = ['columnId' => $columnId, 'value' => $value];
		}
		$row->setDataByAlias($dataByAlias);
		return $row;
	}

	public function testSelfCarriesTheMetadataInUtcWithTheUuidAsId(): void {
		$this->assertSame([
			'id' => self::ROW_UUID,
			'rowId' => 12,
			'tableId' => 3,
			'createdBy' => 'alice',
			'createdAt' => '2026-10-16T12:30:00+00:00',
			'updatedBy' => 'bob',
			'updatedAt' => '2026-01-16T08:00:00+00:00',
		], $this->serializer->serialize($this->row([]))['@self']);
	}

	public function testEveryColumnHasAPropertyAlsoWithoutAStoredCell(): void {
		$object = $this->serializer->serialize($this->row([1 => 'Alice']));

		$this->assertSame(['@self', 'name', 'meeting', 'birthday', 'website', 'project', 'is_active', 'age', 'starts_at'], array_keys($object));
		$this->assertSame('Alice', $object['name']);
		$this->assertNull($object['age']);
	}

	public function testOnlyTheColumnsTheRowIsReadWithArePresent(): void {
		$object = $this->serializer->serialize($this->row([1 => 'Alice', 7 => 42], [7]));

		$this->assertSame(['@self', 'age'], array_keys($object));
	}

	public function testAColumnNamedLikeTheMetadataDoesNotReplaceIt(): void {
		$row = $this->row([]);
		$row->setColumnsByAlias(['@self' => $this->columns[1]]);
		$row->setDataByAlias(['@self' => ['columnId' => 1, 'value' => 'Alice']]);

		$this->assertSame(self::ROW_UUID, $this->serializer->serialize($row)['@self']['id']);
	}

	public function testDatetimesGetTheOffsetOfTheInstanceTimezone(): void {
		$object = $this->serializer->serialize($this->row([2 => '2026-10-16 14:30', 3 => '2026-10-16']));
		$this->assertSame('2026-10-16T14:30:00+02:00', $object['meeting']);
		$this->assertSame('2026-10-16', $object['birthday']);
	}

	public function testATimeIsReturnedAsItIsStored(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame('09:30', $this->serializer->serialize($this->row([8 => '09:30']))['starts_at']);
	}

	public function testADatetimeInTheDaylightSavingGapIsReadAsTheMomentAfterTheJump(): void {
		$object = $this->serializer->serialize($this->row([2 => '2026-03-29 02:30']));
		$this->assertSame('2026-03-29T03:30:00+02:00', $object['meeting']);
	}

	public function testUnknownDatetimeFormatsAreLoggedOncePerCall(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->anything(), $this->callback(static fn (array $context): bool => $context['count'] === 2 && $context['example'] === 'soon'));

		$objects = $this->serializer->serializeAll([$this->row([2 => 'soon']), $this->row([2 => 'later'])]);

		$this->assertSame('soon', $objects[0]['meeting']);
	}

	public function testLinkBecomesAnObject(): void {
		$stored = json_encode(['title' => 'Nextcloud', 'value' => 'https://nextcloud.com', 'providerId' => 'url']);
		$this->assertSame(
			['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url'],
			$this->serializer->serialize($this->row([4 => $stored]))['website'],
		);
	}

	public function testRelationBecomesTheUuidOfItsTarget(): void {
		$this->rowSleeveMapper->expects($this->once())
			->method('findUuidsByIds')
			->with([42, 43, 44])
			->willReturn([42 => self::TARGET_UUID, 43 => null]);

		$objects = $this->serializer->serializeAll([$this->row([5 => 42]), $this->row([5 => 43]), $this->row([5 => 44])]);

		$this->assertSame(self::TARGET_UUID, $objects[0]['project']);
		// a target without a uuid yet keeps its row id instead of losing the relation
		$this->assertSame(43, $objects[1]['project']);
		// a target that no longer exists is no relation
		$this->assertNull($objects[2]['project']);
	}

	public function testOtherValuesAndEmptyCells(): void {
		$object = $this->serializer->serialize($this->row([1 => 'Alice', 6 => 'true', 7 => 42, 2 => '']));
		$this->assertSame('Alice', $object['name']);
		$this->assertSame('true', $object['is_active']);
		$this->assertSame(42, $object['age']);
		$this->assertNull($object['meeting']);
	}

	public function testWhatIsReadCanBeWrittenBackUnchanged(): void {
		$this->rowSleeveMapper->method('findUuidsByIds')->willReturn([42 => self::TARGET_UUID]);
		$row2Mapper = $this->createMock(Row2Mapper::class);
		$row2Mapper->method('findIdByUuid')->with(self::TARGET_UUID)->willReturn(42);
		$converter = new RowObjectInputConverter($row2Mapper, $this->instanceTimezone, $this->serializer);
		$stored = [
			1 => 'Alice',
			2 => '2026-10-16 14:30',
			3 => '2026-10-16',
			4 => json_encode(['title' => 'Nextcloud', 'value' => 'https://nextcloud.com', 'providerId' => 'url']),
			5 => 42,
			6 => 'true',
			7 => 42,
		];

		$result = $converter->toCells(new RowObjectInput($this->serializer->serialize($this->row($stored))), array_values($this->columns));

		$this->assertSame([], $result['errors']);
		$written = [];
		foreach ($result['cells'] as $cell) {
			$written[$cell['columnId']] = $cell['value'];
		}
		// every column without a stored value is read as null and written back as null
		$expected = array_map(static fn (int $columnId): mixed => $stored[$columnId] ?? null, array_combine(array_keys($this->columns), array_keys($this->columns)));
		$this->assertSame($expected, $written);
	}
}
