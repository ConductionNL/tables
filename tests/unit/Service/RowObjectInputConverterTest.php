<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\Row2Mapper;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\Model\RowObjectInput;
use OCA\Tables\Service\RowObjectInputConverter;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RowObjectInputConverterTest extends TestCase {
	private const TARGET_UUID = '0199f1a0-7c3e-7d2a-9b1e-4f5a6b7c8d9e';

	private Row2Mapper&MockObject $row2Mapper;
	private RowObjectInputConverter $converter;

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('default_timezone', 'UTC')->willReturn('Europe/Amsterdam');
		$this->row2Mapper = $this->createMock(Row2Mapper::class);
		$this->converter = new RowObjectInputConverter($this->row2Mapper, new InstanceTimezone($config));
	}

	private function column(int $id, string $type, string $subtype = '', ?string $technicalName = null): Column {
		$column = new Column();
		$column->setId($id);
		$column->setType($type);
		$column->setSubtype($subtype);
		$column->setTechnicalName($technicalName ?? $type . ($subtype !== '' ? '_' . $subtype : ''));
		return $column;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array{cells: array<int, mixed>, errors: array<string, string>}
	 */
	private function convert(array $values, Column ...$columns): array {
		$result = $this->converter->toCells(new RowObjectInput($values), $columns);
		$cells = [];
		foreach ($result['cells'] as $cell) {
			$cells[$cell['columnId']] = $cell['value'];
		}
		return ['cells' => $cells, 'errors' => $result['errors']];
	}

	public function testDatetimeWithOffsetIsStoredInTheInstanceTimezone(): void {
		$column = $this->column(1, Column::TYPE_DATETIME);
		$this->assertSame([1 => '2026-10-16 14:30'], $this->convert(['datetime' => '2026-10-16T12:30:00Z'], $column)['cells']);
		$this->assertSame([1 => '2026-10-16 14:30'], $this->convert(['datetime' => '2026-10-16T14:30:00+02:00'], $column)['cells']);
		$this->assertSame([1 => '2026-01-16 13:30'], $this->convert(['datetime' => '2026-01-16T12:30:00+00:00'], $column)['cells']);
	}

	public function testDatetimeWithoutOffsetIsReadInTheInstanceTimezone(): void {
		$this->assertSame([1 => '2026-10-16 14:30'], $this->convert(['datetime' => '2026-10-16T14:30'], $this->column(1, Column::TYPE_DATETIME))['cells']);
	}

	public function testInvalidDatetimeIsReported(): void {
		$result = $this->convert(['datetime' => 'tomorrow'], $this->column(1, Column::TYPE_DATETIME));
		$this->assertSame([], $result['cells']);
		$this->assertArrayHasKey('datetime', $result['errors']);
	}

	public function testDateAndTimeAreLeftToTheirColumnType(): void {
		$result = $this->convert(
			['datetime_date' => '2026-10-16', 'datetime_time' => '14:30'],
			$this->column(1, Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_DATE),
			$this->column(2, Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_TIME),
		);
		$this->assertSame([1 => '2026-10-16', 2 => '14:30'], $result['cells']);
	}

	public function testLinkObjectBecomesTheStoredLinkJson(): void {
		$result = $this->convert(['text_link' => ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url']], $this->column(1, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK));
		$this->assertSame(['title' => 'Nextcloud', 'value' => 'https://nextcloud.com', 'providerId' => 'url'], json_decode($result['cells'][1], true));
	}

	public function testLinkWithoutUrlIsReported(): void {
		$this->assertArrayHasKey('text_link', $this->convert(['text_link' => ['title' => 'Nextcloud']], $this->column(1, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK))['errors']);
	}

	public function testNonNumericNumberIsReported(): void {
		$result = $this->convert(['number' => '12 apples'], $this->column(1, Column::TYPE_NUMBER));
		$this->assertSame(['number' => 'Expected a number.'], $result['errors']);
	}

	public function testRelationByUuidBecomesTheRowId(): void {
		$this->row2Mapper->method('findIdByUuid')->with(self::TARGET_UUID)->willReturn(42);
		$this->assertSame([1 => 42], $this->convert(['relation' => strtoupper(self::TARGET_UUID)], $this->column(1, Column::TYPE_RELATION))['cells']);
	}

	public function testRelationByUnknownUuidIsReported(): void {
		$this->row2Mapper->method('findIdByUuid')->willReturn(null);
		$this->assertSame(['relation' => 'There is no row with this uuid.'], $this->convert(['relation' => self::TARGET_UUID], $this->column(1, Column::TYPE_RELATION))['errors']);
	}

	public function testRelationByRowIdOrLabelIsPassedOn(): void {
		$this->row2Mapper->expects($this->never())->method('findIdByUuid');
		$column = $this->column(1, Column::TYPE_RELATION);
		$this->assertSame([1 => 42], $this->convert(['relation' => 42], $column)['cells']);
		$this->assertSame([1 => 'Project X'], $this->convert(['relation' => 'Project X'], $column)['cells']);
	}

	public function testUsergroupDropsTheDisplayName(): void {
		$result = $this->convert(['usergroup' => [['id' => 'alice', 'type' => 0, 'displayName' => 'Alice']]], $this->column(1, Column::TYPE_USERGROUP));
		$this->assertSame([1 => [['id' => 'alice', 'type' => 0]]], $result['cells']);
	}

	public function testUsergroupWithoutTypeIsReported(): void {
		$this->assertArrayHasKey('usergroup', $this->convert(['usergroup' => [['id' => 'alice']]], $this->column(1, Column::TYPE_USERGROUP))['errors']);
	}

	public function testCheckAcceptsBooleansAndStrings(): void {
		$column = $this->column(1, Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK);
		$this->assertSame([1 => 'true'], $this->convert(['selection_check' => true], $column)['cells']);
		$this->assertSame([1 => 'false'], $this->convert(['selection_check' => 'false'], $column)['cells']);
	}

	public function testSelfIsIgnoredAndUnknownPropertiesAreReported(): void {
		$result = $this->convert(['@self' => ['id' => self::TARGET_UUID], 'text_line' => 'Hello', 'nickname' => 'x'], $this->column(1, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE));
		$this->assertSame([1 => 'Hello'], $result['cells']);
		$this->assertSame(['nickname'], array_keys($result['errors']));
	}

	public function testColumnWithoutTechnicalNameIsAddressedByItsId(): void {
		$column = $this->column(7, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, '');
		$this->assertSame([7 => 'Hello'], $this->convert(['column_7' => 'Hello'], $column)['cells']);
	}

	public function testNullClearsTheCell(): void {
		$this->assertSame([1 => null], $this->convert(['datetime' => null], $this->column(1, Column::TYPE_DATETIME))['cells']);
	}
}
