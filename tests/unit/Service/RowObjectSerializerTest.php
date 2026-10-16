<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
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
		];
		$columnMapper = $this->createMock(ColumnMapper::class);
		$columnMapper->method('findAll')->willReturnCallback(
			fn (array $ids): array => array_values(array_intersect_key($this->columns, array_flip($ids))),
		);
		$this->rowSleeveMapper = $this->createMock(RowSleeveMapper::class);
		$this->serializer = new RowObjectSerializer($columnMapper, $this->rowSleeveMapper, $this->instanceTimezone, $this->createMock(LoggerInterface::class));
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
	 * @param array<int, mixed> $valuesByColumnId
	 */
	private function row(array $valuesByColumnId): Row2 {
		$row = new Row2();
		$row->setId(12);
		$row->setUuid(self::ROW_UUID);
		$row->setTableId(3);
		$row->setCreatedBy('alice');
		$row->setCreatedAt('2026-10-16 12:30:00');
		$row->setLastEditBy('bob');
		$row->setLastEditAt('2026-01-16 08:00:00');
		$dataByAlias = [];
		foreach ($valuesByColumnId as $columnId => $value) {
			$dataByAlias[$this->columns[$columnId]->getTechnicalName()] = ['columnId' => $columnId, 'value' => $value];
		}
		$row->setDataByAlias($dataByAlias);
		return $row;
	}

	public function testSelfCarriesTheMetadataWithTheUuidAsId(): void {
		$this->assertSame([
			'id' => self::ROW_UUID,
			'rowId' => 12,
			'tableId' => 3,
			'createdBy' => 'alice',
			'createdAt' => '2026-10-16T14:30:00+02:00',
			'lastEditBy' => 'bob',
			'lastEditAt' => '2026-01-16T09:00:00+01:00',
		], $this->serializer->serialize($this->row([]))['@self']);
	}

	public function testDatetimesGetTheOffsetOfTheInstanceTimezone(): void {
		$object = $this->serializer->serialize($this->row([2 => '2026-10-16 14:30', 3 => '2026-10-16']));
		$this->assertSame('2026-10-16T14:30:00+02:00', $object['meeting']);
		$this->assertSame('2026-10-16', $object['birthday']);
	}

	public function testLinkBecomesAnObject(): void {
		$stored = json_encode(['title' => 'Nextcloud', 'value' => 'https://nextcloud.com', 'providerId' => 'url']);
		$this->assertSame(
			['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url'],
			$this->serializer->serialize($this->row([4 => $stored]))['website'],
		);
	}

	public function testRelationBecomesTheUuidOfItsTarget(): void {
		$this->rowSleeveMapper->expects($this->once())->method('findUuidsByIds')->with([42, 43])->willReturn([42 => self::TARGET_UUID]);
		$objects = $this->serializer->serializeAll([$this->row([5 => 42]), $this->row([5 => 43])]);
		$this->assertSame(self::TARGET_UUID, $objects[0]['project']);
		// a target without a uuid yet keeps its row id instead of losing the relation
		$this->assertSame(43, $objects[1]['project']);
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
		$converter = new RowObjectInputConverter($row2Mapper, $this->instanceTimezone);
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
		$this->assertSame($stored, $written);
	}
}
