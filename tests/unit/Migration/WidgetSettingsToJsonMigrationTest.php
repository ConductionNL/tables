<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Migration;

use OC\DB\SchemaWrapper;
use OC\Migration\NullOutput;
use OCA\Tables\Migration\Version2400Date20261022000001;
use OCA\Tables\Migration\Version2400Date20261022000002;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\Migration\SimpleMigrationStep;

/**
 * Runs against the database: an instance that stored widget settings as one row per
 * setting is upgraded to one JSON object per widget.
 */
class WidgetSettingsToJsonMigrationTest extends DatabaseTestCase {
	private const PAGE = 990101;

	protected function tearDown(): void {
		$this->runUpgrade();
		$qb = $this->connectionAdapter->getQueryBuilder();
		$qb->delete(Version2400Date20261022000001::WIDGET_TABLE)
			->where($qb->expr()->eq('page_id', $qb->createNamedParameter(self::PAGE, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		parent::tearDown();
	}

	public function testSettingRowsBecomeOneJsonObjectAndTheTableIsDropped(): void {
		$this->restoreRowShape();
		$header = $this->insertWidget('header');
		$data = $this->insertWidget('data');
		$this->insertSetting($header, 'text', '"Projects"');
		$this->insertSetting($header, 'showTitle', 'false');
		$this->insertSetting($header, 'level', '2');
		$this->insertSetting($header, 'ratio', '1.5');
		$this->insertSetting($header, 'color', null);

		$this->runUpgrade();

		$expected = '{"text":"Projects","showTitle":false,"level":2,"ratio":1.5,"color":null}';
		$this->assertSame($expected, $this->storedSettings($header));
		$this->assertNull($this->storedSettings($data));
		$this->assertFalse($this->connectionAdapter->tableExists(Version2400Date20261022000001::SETTING_TABLE));

		$this->runUpgrade();

		$this->assertSame($expected, $this->storedSettings($header));
		$this->assertNull($this->storedSettings($data));
		$this->assertFalse($this->connectionAdapter->tableExists(Version2400Date20261022000001::SETTING_TABLE));
	}

	public function testAnInstanceWithoutTheSettingTableIsLeftAsItIs(): void {
		$widget = $this->insertWidget('text', '{"text":"Kept"}');

		$this->runUpgrade();

		$this->assertSame('{"text":"Kept"}', $this->storedSettings($widget));
		$this->assertFalse($this->connectionAdapter->tableExists(Version2400Date20261022000001::SETTING_TABLE));
	}

	/**
	 * Puts the schema back the way the first grid page migration left it: no settings
	 * column on the widget, one row per setting in a separate table.
	 */
	private function restoreRowShape(): void {
		$schema = new SchemaWrapper($this->connection);
		$schema->getTable(Version2400Date20261022000001::WIDGET_TABLE)->dropColumn('settings');
		$table = $schema->createTable(Version2400Date20261022000001::SETTING_TABLE);
		$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('widget_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('value', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['widget_id', 'name'], 'tables_ctx_wsetting_uq');
		$this->connection->migrateToSchema($schema->getWrappedSchema());
	}

	private function runUpgrade(): void {
		$this->runStep(new Version2400Date20261022000001($this->connectionAdapter));
		$this->runStep(new Version2400Date20261022000002());
	}

	/**
	 * Runs one step the way the migration service does.
	 */
	private function runStep(SimpleMigrationStep $step): void {
		$output = new NullOutput();
		$schema = $step->changeSchema($output, fn (): SchemaWrapper => new SchemaWrapper($this->connection), []);
		if ($schema instanceof SchemaWrapper) {
			$this->connection->migrateToSchema($schema->getWrappedSchema());
			$schema->performDropTableCalls();
		}
		$step->postSchemaChange($output, fn (): SchemaWrapper => new SchemaWrapper($this->connection), []);
	}

	private function insertWidget(string $type, ?string $settings = null): int {
		$values = [
			'page_id' => self::PAGE,
			'type' => $type,
			'grid_x' => 0,
			'grid_y' => 0,
			'grid_width' => 1,
			'grid_height' => 1,
		];
		if ($settings !== null) {
			$values['settings'] = $settings;
		}
		$qb = $this->connectionAdapter->getQueryBuilder();
		$qb->insert(Version2400Date20261022000001::WIDGET_TABLE);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	private function insertSetting(int $widgetId, string $name, ?string $value): void {
		$qb = $this->connectionAdapter->getQueryBuilder();
		$qb->insert(Version2400Date20261022000001::SETTING_TABLE)
			->setValue('widget_id', $qb->createNamedParameter($widgetId, IQueryBuilder::PARAM_INT))
			->setValue('name', $qb->createNamedParameter($name))
			->setValue('value', $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->executeStatement();
	}

	private function storedSettings(int $widgetId): ?string {
		$qb = $this->connectionAdapter->getQueryBuilder();
		$qb->select('settings')
			->from(Version2400Date20261022000001::WIDGET_TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($widgetId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false || $value === null ? null : (string)$value;
	}
}
