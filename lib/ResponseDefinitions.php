<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * This file is needed, because API extractor will only read this one and not all the php files
 * Otherwise API extractor won't know all the return types...
 */

namespace OCA\Tables;

/**
 * @psalm-type TablesView = array{
 * 	id: int,
 *  uuid: string,
 * 	title: string,
 * 	technicalName: string|null,
 * 	emoji: string|null,
 *  tableId: int,
 * 	ownership: string,
 * 	ownerDisplayName: string|null,
 * 	createdBy: string,
 * 	dataByAlias: array<string, array{columnId: int, value: mixed}>,
 * 	createdAt: string,
 * 	lastEditBy: string,
 * 	lastEditAt: string,
 *  description: string|null,
 *  columns: list<int>,
 *  columnSettings:list<array{columnId: int, order: int, readonly: bool}>,
 *  sort: list<array{columnId: int, mode: 'ASC'|'DESC'}>,
 *  filter: list<list<array{columnId: int, operator: 'begins-with'|'ends-with'|'contains'|'does-not-contain'|'contains-item'|'is-equal'|'is-not-equal'|'is-greater-than'|'is-greater-than-or-equal'|'is-lower-than'|'is-lower-than-or-equal'|'is-empty', value: string|int|float|list<array{id: int, label: string, uuid?: string}>}>>,
 *  layout: 'table'|'tiles'|'gallery',
 *  viewSettings: array{cardBackgroundSource: int|null, cardTitleSource: int|null},
 * 	isShared: bool,
 *	favorite: bool,
 * 	onSharePermissions: ?array{
 * 		read: bool,
 * 		create: bool,
 *     	update: bool,
 * 		delete: bool,
 * 		manage: bool,
 * 	},
 *  hasShares: bool,
 *  rowsCount: int,
 *  isFederated: bool,
 *  sidebarOrder: int|null,
 * }
 *
 * @psalm-type TablesTable = array{
 * 	id: int,
 *  uuid: string,
 * 	title: string,
 * 	emoji: string|null,
 * 	ownership: string,
 * 	ownerDisplayName: string,
 * 	createdBy: string,
 * 	createdAt: string,
 * 	lastEditBy: string,
 * 	lastEditAt: string,
 *	archived: bool,
 *	favorite: bool,
 * 	isShared: bool,
 * 	onSharePermissions: ?array{
 * 		read: bool,
 * 		create: bool,
 *     	update: bool,
 * 		delete: bool,
 * 		manage: bool,
 * 	},
 *  hasShares: bool,
 *  rowsCount: int,
 *  description: string,
 *  views: list<TablesView>,
 *  columnsCount: int,
 *  columnOrder: list<array{columnId: int, order: int}>,
 *  sort: list<array{columnId: int, mode: 'ASC'|'DESC'}>,
 *  isFederated: bool,
 * }
 *
 * @psalm-type TablesIndex = array{
 * 	tables: list<TablesTable>,
 *  views: list<TablesView>,
 * }
 *
 * @psalm-type TablesRow = array{
 * 	id: int,
 * 	uuid: ?string,
 * 	tableId: int,
 * 	createdBy: string,
 * 	createdAt: string,
 * 	lastEditBy: string,
 * 	lastEditAt: string,
 *  data: ?array{columnId: int, value: mixed},
 *  dataByAlias: array<string, array{columnId: int, value: mixed}>,
 * }
 *
 * @psalm-type TablesRowPage = array{
 * 	results: list<TablesRow>,
 * 	total: int,
 * 	limit: int,
 * 	page: int,
 * 	pages: int,
 * }
 *
 * @psalm-type TablesRowSelf = array{
 * 	id: ?string,
 * 	rowId: int,
 * 	tableId: int,
 * 	createdBy: string,
 * 	createdAt: ?string,
 * 	lastEditBy: string,
 * 	lastEditAt: ?string,
 * }
 *
 * @psalm-type TablesRowLink = array{
 * 	title: string,
 * 	url: string,
 * 	providerId: string,
 * }
 *
 * @psalm-type TablesRowUsergroup = array{
 * 	id: string,
 * 	type: int,
 * 	displayName: string,
 * }
 *
 * @psalm-type TablesRowObjectValue = string|int|float|list<int>|TablesRowLink|list<TablesRowUsergroup>
 *
 * @psalm-type TablesRowObject = array{'@self': TablesRowSelf}&array<string, TablesRowObjectValue|TablesRowSelf|null>
 *
 * @psalm-type TablesRowObjectPage = array{
 * 	results: list<TablesRowObject>,
 * 	total: int,
 * 	limit: int,
 * 	page: int,
 * 	pages: int,
 * }
 *
 * @psalm-type TablesRowValidationError = array{
 * 	message: string,
 * 	errors: array<string, string>,
 * }
 *
 * @psalm-type TablesPublicRow = array{
 *    id: int,
 *    createdAt: string,
 *    lastEditAt: string,
 *    data: ?array{columnId: int, value: mixed},
 *    dataByAlias: array<string, array{columnId: int, value: mixed}>,
 *  }
 *
 * @psalm-type TablesLinkShare = array{
 *    shareToken: string,
 *    url: string,
 *  }
 *
 * @psalm-type TablesShare = array{
 * 	id: int,
 * 	sender: string,
 * 	receiver: string,
 *  receiverDisplayName: string,
 * 	receiverType: string,
 * 	nodeId: int,
 * 	nodeType: string,
 * 	permissionRead: bool,
 *  permissionCreate: bool,
 *  permissionUpdate: bool,
 *  permissionDelete: bool,
 * 	permissionManage: bool,
 *  createdAt: string,
 *  createdBy: string,
 * }
 *
 * @psalm-type TablesColumn = array{
 *  id: int,
 *  uuid: string,
 *  title: string,
 *  technicalName: string,
 *  tableId: int,
 *  createdBy: string,
 *  createdByDisplayName: string,
 *  createdAt: string,
 *  lastEditBy: string,
 *  lastEditByDisplayName: string,
 *  lastEditAt: string,
 *  type: string,
 *  subtype: string,
 *  baseSubtype: string,
 *  broken: bool,
 *  failingValuesCount?: int,
 *  mandatory: bool,
 *  description: string,
 *  orderWeight: int,
 *  numberDefault: float,
 *  numberMin: float,
 *  numberMax: float,
 *  numberDecimals: int,
 *  numberPrefix: string,
 *  numberSuffix: string,
 *  textDefault: string,
 *  textAllowedPattern: string,
 *  textMaxLength: int,
 *  textUnique: bool,
 *  selectionOptions: string,
 *  selectionDefault: string,
 *  datetimeDefault: string,
 *  usergroupDefault: string,
 *  usergroupMultipleItems: bool,
 *  usergroupSelectUsers: bool,
 *  usergroupSelectGroups: bool,
 *  usergroupSelectTeams: bool,
 *  showUserStatus: bool,
 *  viewColumnInformation: ?array{
 *     columnId: int,
 *     order: int,
 *     readonly: bool,
 *     mandatory: bool,
 *  },
 *  customSettings: ?array{
 *     width: int,
 *     showPreview?: bool,
 *     imagePreviewSize?: int,
 *  },
 * }
 *
 * @psalm-type TablesPublicColumn = array{
 *   id: int,
 *   title: string,
 *   technicalName: string,
 *   createdAt: string,
 *   lastEditAt: string,
 *   type: string,
 *   subtype: string,
 *   baseSubtype: string,
 *   broken: bool,
 *   mandatory: bool,
 *   description: string,
 *   orderWeight: int,
 *   numberDefault: float,
 *   numberMin: float,
 *   numberMax: float,
 *   numberDecimals: int,
 *   numberPrefix: string,
 *   numberSuffix: string,
 *   textDefault: string,
 *   textAllowedPattern: string,
 *   textMaxLength: int,
 *   textUnique: bool,
 *   selectionOptions: string,
 *   selectionDefault: string,
 *   datetimeDefault: string,
 *   usergroupDefault: string,
 *   usergroupMultipleItems: bool,
 *   usergroupSelectUsers: bool,
 *   usergroupSelectGroups: bool,
 *   usergroupSelectTeams: bool,
 *   showUserStatus: bool,
 *   viewColumnInformation: ?array{
 *      columnId: int,
 *      order: int,
 *      readonly: bool,
 *      mandatory: bool,
 *   },
 *   customSettings: ?array{
 *      width: int,
 *      showPreview?: bool,
 *      imagePreviewSize?: int,
 *   },
 *  }
 *
 * @psalm-type TablesImportState = array{
 *  found_columns_count: int,
 *  matching_columns_count: int,
 *  created_columns_count: int,
 *  inserted_rows_count: int,
 *  updated_rows_count: int,
 *  errors_parsing_count: int,
 *  errors_count: int,
 *  row_errors: list<array{row: int, message: string}>,
 * }
 *
 * @psalm-type TablesContext = array{
 *   id: int,
 *   name: string,
 *   iconName: string,
 *   description: string,
 *   owner: string,
 *   ownerType: int,
 * }
 *
 * @psalm-type TablesContextNavigation = array{
 *     id: int,
 *     shareId: int,
 *     displayMode: int,
 *     userId: string,
 * }
 *
 * @psalm-type TablesNotifyConfig = array{
 *     notify-assigned: bool,
 *     notify-column: bool,
 *     notify-row: bool,
 * }
 *
 * @psalm-type TablesFormat = array{
 *     id: int,
 *     technicalName: string,
 *     label: string,
 *     displayLabel: string,
 *     example: ?string,
 *     type: string,
 *     rule: array<string, mixed>,
 *     enabled: bool,
 *     source: string,
 *     version: string,
 *     createdAt: string,
 *     createdBy: ?string,
 *     updatedAt: string,
 *     updatedBy: ?string,
 * }
 *
 * @psalm-type TablesFormatPack = array{
 *     name: string,
 *     label: string,
 *     version: string,
 *     formatsCount: int,
 *     installedCount: int,
 *     updateAvailable: bool,
 * }
 * @psalm-type TablesFormatPackItem = array{
 *     technicalName: string,
 *     label: string,
 *     status: 'new'|'unchanged'|'update'|'conflict'|'core',
 *     version: string,
 *     installedVersion: ?string,
 *     installedSource: ?string,
 *     columnsCount: int,
 *     failingValuesCount: int,
 * }
 * @psalm-type TablesFormatPackImport = array{
 *     created: list<string>,
 *     replaced: list<string>,
 *     kept: list<string>,
 *     unchanged: list<string>,
 *     skipped: list<string>,
 * }
 * @psalm-type TablesSchemaImport = array{
 *     table: TablesTable,
 *     formats: TablesFormatPackImport,
 *     brokenColumns: list<string>,
 *     warnings: list<string>,
 * }
 * @psalm-type TablesSchemaColumnSummary = array{
 *     technicalName: string,
 *     title: string,
 *     type: string,
 *     subtype: string,
 * }
 * @psalm-type TablesSchemaChanges = array{
 *     title: array{from: string, to: string},
 *     description: array{from: string, to: string},
 *     columns: array{
 *         add: list<TablesSchemaColumnSummary>,
 *         modify: list<array{technicalName: string, title: string, changes: array<string, array{from: mixed, to: mixed}>}>,
 *         remove: list<TablesSchemaColumnSummary>,
 *     },
 *     formats: list<TablesFormatPackItem>,
 *     brokenColumns: list<string>,
 *     hasChanges: bool,
 * }
 */
class ResponseDefinitions {
}
