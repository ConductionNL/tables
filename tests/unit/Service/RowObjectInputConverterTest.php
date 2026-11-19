<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
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

class RowObjectInputConverterTest extends TestCase {
	private const TARGET_UUID = '0199f1a0-7c3e-7d2a-9b1e-4f5a6b7c8d9e';

	private Row2Mapper&MockObject $row2Mapper;
	private RowSleeveMapper&MockObject $rowSleeveMapper;
	private RowObjectInputConverter $converter;

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('default_timezone', 'UTC')->willReturn('Europe/Amsterdam');
		$instanceTimezone = new InstanceTimezone($config);
		$this->row2Mapper = $this->createMock(Row2Mapper::class);
		$this->rowSleeveMapper = $this->createMock(RowSleeveMapper::class);
		$serializer = new RowObjectSerializer($this->rowSleeveMapper, $instanceTimezone, $this->createMock(LoggerInterface::class));
		$this->converter = new RowObjectInputConverter($this->row2Mapper, $instanceTimezone, $serializer);
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

	public function testImpossibleDatetimeIsReported(): void {
		$result = $this->convert(['datetime' => '2026-02-30T10:00'], $this->column(1, Column::TYPE_DATETIME));
		$this->assertSame([], $result['cells']);
		$this->assertArrayHasKey('datetime', $result['errors']);
	}

	public function testDatetimeWithSecondsIsReported(): void {
		$column = $this->column(1, Column::TYPE_DATETIME);
		$result = $this->convert(['datetime' => '2026-10-16T14:30:45+02:00'], $column);
		$this->assertSame([], $result['cells']);
		$this->assertArrayHasKey('datetime', $result['errors']);
		$this->assertSame([1 => '2026-10-16 14:30'], $this->convert(['datetime' => '2026-10-16T14:30:00.000+02:00'], $column)['cells']);
	}

	public function testWallTimeInTheDaylightSavingGapIsReported(): void {
		$column = $this->column(1, Column::TYPE_DATETIME);
		$result = $this->convert(['datetime' => '2026-03-29T02:30'], $column);
		$this->assertSame([], $result['cells']);
		$this->assertArrayHasKey('datetime', $result['errors']);
		$this->assertSame([1 => '2026-03-29 03:30'], $this->convert(['datetime' => '2026-03-29T01:30:00Z'], $column)['cells']);
	}

	public function testOnlyTheStoredOccurrenceOfTheRepeatedHourIsAccepted(): void {
		$column = $this->column(1, Column::TYPE_DATETIME);
		foreach (['2026-10-25T02:30:00+02:00', '2026-10-25T00:30:00Z'] as $firstOccurrence) {
			$result = $this->convert(['datetime' => $firstOccurrence], $column);
			$this->assertSame([], $result['cells']);
			$this->assertSame(['datetime' => 'This time occurs twice in the timezone of the instance and can only be stored as 2026-10-25T02:30:00+01:00.'], $result['errors']);
		}
		foreach (['2026-10-25T02:30:00+01:00', '2026-10-25T01:30:00Z', '2026-10-25T02:30'] as $secondOccurrence) {
			$this->assertSame([1 => '2026-10-25 02:30'], $this->convert(['datetime' => $secondOccurrence], $column)['cells']);
		}
	}

	/**
	 * @return array<string, array{string, string, mixed, mixed}>
	 */
	public static function valueAsItIsRead(): array {
		$uiLink = '{"title":"Nextcloud","subline":"URL","providerId":"url","value":"https://nextcloud.com/a/b"}';
		return [
			'a link the UI stored' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com/a/b', 'providerId' => 'url'], $uiLink],
			'a link without the title and provider the reader fills in' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, ['url' => 'https://nextcloud.com'], '{"value":"https://nextcloud.com"}'],
			'a link stored as a plain URL' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, 'https://nextcloud.com', 'https://nextcloud.com'],
			'a datetime with another offset' => [Column::TYPE_DATETIME, '', '2026-10-16T12:30:00Z', '2026-10-16 14:30'],
			'a datetime with seconds' => [Column::TYPE_DATETIME, '', '2026-10-16T14:30:15+02:00', '2026-10-16 14:30:15'],
			'a datetime in the daylight saving gap' => [Column::TYPE_DATETIME, '', '2026-03-29T03:30:00+02:00', '2026-03-29 02:30'],
			'a datetime in an unknown format' => [Column::TYPE_DATETIME, '', 'soon', 'soon'],
			'a number as a string' => [Column::TYPE_NUMBER, '', '41', 41.0],
			'a checkbox as a boolean' => [Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK, true, 'true'],
			'a usergroup without display names' => [Column::TYPE_USERGROUP, '', [['id' => 'alice', 'type' => 0]], [['id' => 'alice', 'type' => 0, 'displayName' => 'Alice']]],
			'a multi-selection' => [Column::TYPE_SELECTION, 'multi', [0, 2], [0, 2]],
			'a relation by its uuid in capitals' => [Column::TYPE_RELATION, '', strtoupper(self::TARGET_UUID), 42],
			'a relation by its row id' => [Column::TYPE_RELATION, '', 42, 42],
			'null for a relation to a row that no longer exists' => [Column::TYPE_RELATION, '', null, 43],
			'null for a missing cell' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, null, null],
			'an empty string for an empty cell' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, '', ''],
		];
	}

	/**
	 * @dataProvider valueAsItIsRead
	 */
	public function testAValueAsItIsReadIsRecognised(string $type, string $subtype, mixed $given, mixed $stored): void {
		$this->row2Mapper->method('findIdByUuid')->with(self::TARGET_UUID)->willReturn(42);
		$this->rowSleeveMapper->method('findUuidsByIds')->willReturnCallback(static fn (array $ids): array => array_intersect_key([42 => self::TARGET_UUID], array_flip($ids)));

		$this->assertTrue($this->isReadAs($this->column(1, $type, $subtype), $given, $stored));
	}

	/**
	 * @return array<string, array{string, string, mixed, mixed}>
	 */
	public static function valueOtherThanTheOneRead(): array {
		$uiLink = '{"title":"Nextcloud","subline":"URL","providerId":"url","value":"https://nextcloud.com/a/b"}';
		return [
			'a link with another title' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, ['title' => 'Other', 'url' => 'https://nextcloud.com/a/b', 'providerId' => 'url'], $uiLink],
			'a link with another url' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com/a', 'providerId' => 'url'], $uiLink],
			'a link without the title it has' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, ['url' => 'https://nextcloud.com/a/b'], $uiLink],
			'another instant' => [Column::TYPE_DATETIME, '', '2026-10-16T14:30:00Z', '2026-10-16 14:30'],
			'other seconds' => [Column::TYPE_DATETIME, '', '2026-10-16T14:30:00+02:00', '2026-10-16 14:30:15'],
			'another number' => [Column::TYPE_NUMBER, '', 41.5, 41.0],
			'a text that spells the same number differently' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, '1e3', '1000'],
			'another checkbox value' => [Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK, false, 'true'],
			'another user' => [Column::TYPE_USERGROUP, '', [['id' => 'bob', 'type' => 0]], [['id' => 'alice', 'type' => 0, 'displayName' => 'Alice']]],
			'reordered options' => [Column::TYPE_SELECTION, 'multi', [2, 0], [0, 2]],
			'another relation' => [Column::TYPE_RELATION, '', '0199f1a0-7c3e-7d2a-9b1e-000000000000', 42],
			'another row id' => [Column::TYPE_RELATION, '', 41, 42],
			'a cleared relation' => [Column::TYPE_RELATION, '', null, 42],
			'a label of digits equal to the stored row id' => [Column::TYPE_RELATION, '', '42', 42],
			'a value for an empty cell' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, 'x', null],
			'null for a value' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, null, 'x'],
			'a day that rolls over into the stored one' => [Column::TYPE_DATETIME, '', '2026-02-30T14:30', '2026-03-02 14:30'],
			'options as strings' => [Column::TYPE_SELECTION, 'multi', ['1', '2'], [1, 2]],
			'an option as a float' => [Column::TYPE_SELECTION, 'multi', [1.0, 2], [1, 2]],
			'a usergroup type as a string' => [Column::TYPE_USERGROUP, '', [['id' => 'alice', 'type' => '0']], [['id' => 'alice', 'type' => 0, 'displayName' => 'Alice']]],
			'a link title as a number' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK, ['title' => 5, 'url' => 'https://nextcloud.com'], '{"title":"5","value":"https://nextcloud.com"}'],
			'a list for an empty text' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE, [], ''],
		];
	}

	/**
	 * @dataProvider valueOtherThanTheOneRead
	 */
	public function testAValueOtherThanTheOneReadIsNotTakenForIt(string $type, string $subtype, mixed $given, mixed $stored): void {
		$this->rowSleeveMapper->method('findUuidsByIds')->willReturnCallback(static fn (array $ids): array => array_intersect_key([42 => self::TARGET_UUID], array_flip($ids)));

		$this->assertFalse($this->isReadAs($this->column(1, $type, $subtype), $given, $stored));
	}

	public function testARelationToATargetWithoutAUuidIsReadAsItsRowIdOnlyAsAnInteger(): void {
		$this->rowSleeveMapper->method('findUuidsByIds')->willReturn([42 => null]);
		$column = $this->column(1, Column::TYPE_RELATION);

		$this->assertTrue($this->isReadAs($column, 42, 42));
		$this->assertFalse($this->isReadAs($column, '42', 42));
	}

	private function isReadAs(Column $column, mixed $given, mixed $stored): bool {
		$name = RowObjectInputConverter::nameOf($column);
		return $this->converter->findValuesAsRead([$name => $given], [$name => $column], [$column->getId() => $stored]) === [$name => true];
	}

	public function testTheRelationTargetsOfOneWriteAreLookedUpAtOnce(): void {
		$this->rowSleeveMapper->expects($this->once())
			->method('findUuidsByIds')
			->with([42, 43])
			->willReturn([42 => self::TARGET_UUID, 43 => null]);
		$mentor = $this->column(1, Column::TYPE_RELATION, '', 'mentor');
		$project = $this->column(2, Column::TYPE_RELATION, '', 'project');

		$valuesAsRead = $this->converter->findValuesAsRead(
			['mentor' => self::TARGET_UUID, 'project' => 43],
			['mentor' => $mentor, 'project' => $project],
			[1 => 42, 2 => 43],
		);

		$this->assertSame(['mentor' => true, 'project' => true], $valuesAsRead);
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

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function linkWithANonStringPart(): array {
		return [
			'title' => [['url' => 'https://nextcloud.com', 'title' => [1]]],
			'providerId' => [['url' => 'https://nextcloud.com', 'providerId' => 5]],
		];
	}

	/**
	 * @dataProvider linkWithANonStringPart
	 */
	public function testLinkWithANonStringPartIsReported(array $link): void {
		$result = $this->convert(['text_link' => $link], $this->column(1, Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK));
		$this->assertSame([], $result['cells']);
		$this->assertArrayHasKey('text_link', $result['errors']);
	}

	public function testNumericStringIsPassedOnAsANumber(): void {
		$column = $this->column(1, Column::TYPE_NUMBER);
		$this->assertSame([1 => 0.0], $this->convert(['number' => '0.0'], $column)['cells']);
		$this->assertSame([1 => -1.5], $this->convert(['number' => '-1.5'], $column)['cells']);
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
		$this->assertSame(['relation' => 'Relation value does not exist in the target table/view'], $this->convert(['relation' => self::TARGET_UUID], $this->column(1, Column::TYPE_RELATION))['errors']);
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

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function notACheckValue(): array {
		return [
			'other string' => ['foo'],
			'number' => [2],
			'empty string' => [''],
		];
	}

	/**
	 * @dataProvider notACheckValue
	 */
	public function testCheckRefusesWhatIsNeitherTrueNorFalse(mixed $value): void {
		$result = $this->convert(['selection_check' => $value], $this->column(1, Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK));
		$this->assertSame([], $result['cells']);
		$this->assertSame(['selection_check' => 'Expected true or false.'], $result['errors']);
	}

	public function testMultiSelectionTakesAListOfOptionIds(): void {
		$this->assertSame([1 => [0, 2]], $this->convert(['selection_multi' => [0, 2]], $this->column(1, Column::TYPE_SELECTION, 'multi'))['cells']);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function notAListOfOptionIds(): array {
		return [
			'labels' => ['a,zzz'],
			'list of labels' => [['PHP']],
			'object' => [['php' => 0]],
			'number' => [1],
		];
	}

	/**
	 * @dataProvider notAListOfOptionIds
	 */
	public function testMultiSelectionRefusesWhatIsNotAListOfOptionIds(mixed $value): void {
		$result = $this->convert(['selection_multi' => $value], $this->column(1, Column::TYPE_SELECTION, 'multi'));
		$this->assertSame([], $result['cells']);
		$this->assertArrayHasKey('selection_multi', $result['errors']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function columnStoringOneValue(): array {
		return [
			'text line' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE],
			'long text' => [Column::TYPE_TEXT, 'long'],
			'date' => [Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_DATE],
			'time' => [Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_TIME],
			'single selection' => [Column::TYPE_SELECTION, ''],
		];
	}

	/**
	 * @dataProvider columnStoringOneValue
	 */
	public function testListIsRefusedForAColumnThatStoresOneValue(string $type, string $subtype): void {
		$result = $this->convert(['value' => ['a']], $this->column(1, $type, $subtype, 'value'));
		$this->assertSame([], $result['cells']);
		$this->assertSame(['value' => 'Unexpected value type for this column.'], $result['errors']);
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
