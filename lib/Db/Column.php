<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use JsonSerializable;
use OCA\Tables\Constants\ColumnType;
use OCA\Tables\Dto\Column as ColumnDto;
use OCA\Tables\Model\SelectionOptions;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\ValueObject\ViewColumnInformation;
use OCA\Tables\Vendor\Symfony\Component\Uid\Uuid;
use ValueError;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 *
 * @psalm-import-type TablesColumn from ResponseDefinitions
 *
 * @method string|null getUuid()
 * @method setUuid(?string $uuid)
 * @method getTitle(): string
 * @method setTitle(string $title)
 * @method getTechnicalName(): string
 * @method setTechnicalName(?string $technicalName)
 * @method getTableId(): int
 * @method setTableId(int $tableId)
 * @method getCreatedBy(): string
 * @method setCreatedBy(string $createdBy)
 * @method getCreatedByDisplayName(): string
 * @method setCreatedByDisplayName(string $displayName)
 * @method getCreatedAt(): string
 * @method setCreatedAt(string $createdAt)
 * @method getLastEditBy(): string
 * @method setLastEditBy(string $lastEditBy)
 * @method getLastEditByDisplayName(): string
 * @method setLastEditByDisplayName(string $displayName)
 * @method getLastEditAt(): string
 * @method setLastEditAt(string $lastEditAt)
 * @method setType(string $type)
 * @method getSubtype(): string
 * @method setSubtype(string $subtype)
 * @method getMandatory(): bool
 * @method setMandatory(?bool $mandatory)
 * @method getDescription(): string
 * @method setDescription(?string $description)
 * @method getNumberDefault(): float
 * @method setNumberDefault(?float $numberDefault)
 * @method getNumberMin(): float
 * @method setNumberMin(?float $numberMin)
 * @method getNumberMax(): float
 * @method setNumberMax(?float $numberMax)
 * @method getNumberDecimals(): int
 * @method setNumberDecimals(?int $numberDecimals)
 * @method getNumberPrefix(): string
 * @method setNumberPrefix(?string $numberPrefix)
 * @method getNumberSuffix(): string
 * @method setNumberSuffix(?string $numberSuffix)
 * @method getTextDefault(): string
 * @method setTextDefault(?string $textDefault)
 * @method getTextAllowedPattern(): string
 * @method setTextAllowedPattern(?string $textAllowedPattern)
 * @method getTextMaxLength(): int
 * @method setTextMaxLength(?int $textMaxLength)
 * @method getTextUnique(): bool
 * @method setTextUnique(?bool $textUnique)
 * @method string getSelectionOptions()
 * @method setSelectionOptions(?string $selectionOptions)
 * @method setSelectionDefault(?string $selectionDefault)
 * @method getSelectionDefault(): ?string
 * @method getDatetimeDefault(): string
 * @method setDatetimeDefault(?string $datetimeDefault)
 * @method getUsergroupDefault(): string
 * @method setUsergroupDefault(?string $usergroupDefaultArray)
 * @method getUsergroupMultipleItems(): bool
 * @method setUsergroupMultipleItems(?bool $usergroupMultipleItems)
 * @method getUsergroupSelectUsers(): bool
 * @method setUsergroupSelectUsers(?bool $usergroupSelectUsers)
 * @method getUsergroupSelectGroups(): bool
 * @method setUsergroupSelectGroups(?bool $usergroupSelectGroups)
 * @method getUsergroupSelectTeams(): bool
 * @method setUsergroupSelectTeams(?bool $usergroupSelectTeams)
 * @method getShowUserStatus(): bool
 * @method setShowUserStatus(?bool $showUserStatus)
 * @method getViewColumnInformation(): ViewColumnInformation
 * @method setViewColumnInformation(ViewColumnInformation $viewColumnInformation)
 * @method getCustomSettings(): ?string
 * @method setCustomSettings(?string $customSettings)
 * @method isBroken(): bool
 * @method setBroken(bool $broken)
 * @method setFailingValuesCount(?int $failingValuesCount)
 */
class Column extends EntitySuper implements JsonSerializable {
	// Meta column types
	public const TYPE_META_ID = -1;
	public const TYPE_META_CREATED_BY = -2;
	public const TYPE_META_UPDATED_BY = -3;
	public const TYPE_META_CREATED_AT = -4;
	public const TYPE_META_UPDATED_AT = -5;
	// In case a new one is added, add it to isValidMetaTypeId() as well.
	// When we drop PHP 8.0, we can switch to enums.

	public const TYPE_SELECTION = 'selection';
	public const TYPE_TEXT = 'text';
	public const TYPE_NUMBER = 'number';
	public const TYPE_DATETIME = 'datetime';
	public const TYPE_USERGROUP = 'usergroup';
	public const TYPE_RELATION = 'relation';

	public const SUBTYPE_DATETIME_DATE = 'date';
	public const SUBTYPE_DATETIME_TIME = 'time';

	public const SUBTYPE_SELECTION_CHECK = 'check';
	public const SUBTYPE_SELECTION_MULTI = 'selection-multi';

	public const SUBTYPE_TEXT_LINE = 'line';
	public const SUBTYPE_TEXT_LINK = 'link';

	public const META_ID_TITLE = 'id';

	public const RELATION_TYPE = 'relationType';
	public const RELATION_TARGET_ID = 'targetId';
	public const RELATION_LABEL_COLUMN = 'labelColumn';

	/**
	 * Subtypes the app implements itself. They always win over a format with the same name.
	 */
	private const BUILT_IN_SUBTYPES = [
		self::TYPE_TEXT => [self::SUBTYPE_TEXT_LINE, 'long', 'rich', self::SUBTYPE_TEXT_LINK],
		self::TYPE_NUMBER => ['', 'stars', 'progress'],
		self::TYPE_SELECTION => ['', 'multi', self::SUBTYPE_SELECTION_CHECK],
		self::TYPE_DATETIME => ['', self::SUBTYPE_DATETIME_DATE, self::SUBTYPE_DATETIME_TIME],
		self::TYPE_USERGROUP => [''],
		self::TYPE_RELATION => [''],
	];

	/**
	 * The subtype whose behaviour a format subtype builds on, per column type that supports formats.
	 */
	private const FORMAT_BASE_SUBTYPES = [
		self::TYPE_TEXT => self::SUBTYPE_TEXT_LINE,
		self::TYPE_NUMBER => '',
	];

	protected ?string $uuid = null;
	protected ?string $title = null;
	protected ?string $technicalName = null;
	protected ?int $tableId = null;
	protected ?string $createdBy = null;
	protected ?string $createdAt = null;
	protected ?string $lastEditBy = null;
	protected ?string $lastEditAt = null;
	protected ?string $type = null;
	protected ?string $subtype = null;
	protected ?bool $mandatory = null;
	protected ?string $description = null;
	protected ?int $orderWeight = null; // Deprecated

	// type number
	protected ?float $numberDefault = null;
	protected ?float $numberMin = null;
	protected ?float $numberMax = null;
	protected ?int $numberDecimals = null;
	protected ?string $numberPrefix = null;
	protected ?string $numberSuffix = null;

	// type text
	protected ?string $textDefault = null;
	protected ?string $textAllowedPattern = null;
	protected ?int $textMaxLength = null;
	protected ?bool $textUnique = null;

	// type selection
	protected ?string $selectionOptions = null;
	protected ?string $selectionDefault = null;

	// type datetime
	protected ?string $datetimeDefault = null;

	// type usergroup
	protected ?string $usergroupDefault = null;
	protected ?bool $usergroupMultipleItems = null;
	protected ?bool $usergroupSelectUsers = null;
	protected ?bool $usergroupSelectGroups = null;
	protected ?bool $usergroupSelectTeams = null;
	protected ?bool $showUserStatus = null;
	protected ?string $customSettings = null;

	// virtual properties
	protected ?string $createdByDisplayName = null;
	protected ?string $lastEditByDisplayName = null;
	protected ?ViewColumnInformation $viewColumnInformation = null;
	// a column that names a format that is not installed, enabled and meant for its type
	protected bool $broken = false;
	// how many stored values fail the format, reported when a change makes the column check them
	protected ?int $failingValuesCount = null;

	protected const VIRTUAL_PROPERTIES = ['createdByDisplayName', 'lastEditByDisplayName', 'viewColumnInformation', 'broken', 'failingValuesCount'];

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('uuid', 'string');
		$this->addType('tableId', 'integer');
		$this->addType('mandatory', 'boolean');

		// type number
		$this->addType('numberDecimals', 'integer');
		$this->addType('numberMin', 'float');
		$this->addType('numberMax', 'float');
		$this->addType('numberDefault', 'float');

		// type text
		$this->addType('textMaxLength', 'integer');
		$this->addType('textUnique', 'boolean');

		// // type usergroup
		$this->addType('usergroupMultipleItems', 'boolean');
		$this->addType('usergroupSelectUsers', 'boolean');
		$this->addType('usergroupSelectGroups', 'boolean');
		$this->addType('usergroupSelectTeams', 'boolean');
		$this->addType('showUserStatus', 'boolean');

		$this->addType('customSettings', 'string');

		$this->addType('selectionOptions', 'string');

		$this->addType('broken', 'boolean');
		$this->addType('failingValuesCount', 'integer');
	}

	public static function isValidMetaTypeId(int $metaTypeId): bool {
		return in_array($metaTypeId, [
			self::TYPE_META_ID,
			self::TYPE_META_CREATED_BY,
			self::TYPE_META_UPDATED_BY,
			self::TYPE_META_CREATED_AT,
			self::TYPE_META_UPDATED_AT,
		], true);
	}

	public static function isBuiltInSubtype(string $type, string $subtype): bool {
		return in_array($subtype, self::BUILT_IN_SUBTYPES[$type] ?? [], true);
	}

	/**
	 * Whether any column type implements this subtype itself, which a format cannot be named after.
	 */
	public static function isBuiltInSubtypeOfAnyType(string $subtype): bool {
		foreach (self::BUILT_IN_SUBTYPES as $subtypes) {
			if (in_array($subtype, $subtypes, true)) {
				return true;
			}
		}
		return false;
	}

	public static function supportsFormats(string $type): bool {
		return isset(self::FORMAT_BASE_SUBTYPES[$type]);
	}

	/**
	 * The format a subtype names, which is any subtype of a text or number column that the app
	 * does not implement itself.
	 */
	public static function formatNameOf(string $type, string $subtype): ?string {
		if (!self::supportsFormats($type) || $subtype === '' || self::isBuiltInSubtype($type, $subtype)) {
			return null;
		}
		return $subtype;
	}

	public function getFormatName(): ?string {
		return self::formatNameOf((string)$this->type, (string)$this->subtype);
	}

	/**
	 * The built-in subtype this column behaves as: its own subtype, or the one a format builds on.
	 * Other column types keep an unknown subtype, as it decides how their values are stored.
	 */
	public function getBaseSubtype(): string {
		$type = (string)$this->type;
		$subtype = (string)$this->subtype;
		if (self::isBuiltInSubtype($type, $subtype)) {
			return $subtype;
		}
		return self::FORMAT_BASE_SUBTYPES[$type] ?? $subtype;
	}

	public function setter(string $name, array $args): void {
		if ($name === 'uuid') {
			$this->setOrAssignUuid((string)$args[0]);
			return;
		}

		parent::setter($name, $args);
	}

	private function setOrAssignUuid(?string $uuid): void {
		if ($this->uuid !== null) {
			throw new \RuntimeException('This column already has a UUID, they are immutable');
		}
		if ($uuid === null) {
			$this->applyUuid(Uuid::v7()->toRfc4122());
			return;
		}
		$this->applyUuid($uuid);
	}

	private function applyUuid(string $uuid): void {
		$this->uuid = $uuid;
		$this->markFieldUpdated('uuid');
	}

	public static function fromDto(ColumnDto $data): self {
		$column = new self();
		$column->setOrAssignUuid($data->getUuid());
		$column->setTitle($data->getTitle());
		$column->setTechnicalName($data->getTechnicalName());
		$column->setType($data->getType());
		$column->setSubtype($data->getSubtype() ?? '');
		$column->setMandatory($data->isMandatory() ?? false);
		$column->setDescription($data->getDescription() ?? '');
		$column->setTextDefault($data->getTextDefault());
		$column->setTextAllowedPattern($data->getTextAllowedPattern());
		$column->setTextMaxLength($data->getTextMaxLength());
		$column->setTextUnique($data->getTextUnique());
		$column->setNumberDefault($data->getNumberDefault());
		$column->setNumberMin($data->getNumberMin());
		$column->setNumberMax($data->getNumberMax());
		$column->setNumberDecimals($data->getNumberDecimals());
		$column->setNumberPrefix($data->getNumberPrefix() ?? '');
		$column->setNumberSuffix($data->getNumberSuffix() ?? '');
		$selectionOptions = SelectionOptions::createFromInputJsonString($data->getSelectionOptions() ?? '[]', $data->getSelectionDefault(), true);
		$selectionOptions->assignServerManagedUuids();
		$column->setSelectionOptionsCollection($selectionOptions);
		$column->setDatetimeDefault($data->getDatetimeDefault());
		$column->setUsergroupDefault($data->getUsergroupDefault());
		$column->setUsergroupMultipleItems($data->getUsergroupMultipleItems());
		$column->setUsergroupSelectUsers($data->getUsergroupSelectUsers());
		$column->setUsergroupSelectGroups($data->getUsergroupSelectGroups());
		$column->setUsergroupSelectTeams($data->getUsergroupSelectTeams());
		$column->setShowUserStatus($data->getShowUserStatus());
		$column->setCustomSettings($data->getCustomSettings());
		return $column;
	}

	public function getUsergroupDefaultArray():array {
		$default = $this->getUsergroupDefault();
		if ($default !== '' && $default !== null) {
			return \json_decode($default, true) ?? [];
		} else {
			return [];
		}
	}

	public function setUsergroupDefaultArray(array $array):void {
		$json = \json_encode($array);
		$this->setUsergroup($json);
	}

	public function getSelectionOptionsCollection(): SelectionOptions {
		return SelectionOptions::createFromInputJsonString($this->getSelectionOptions() ?? '[]', $this->getSelectionDefault(), true);
	}

	public function setSelectionOptionsCollection(SelectionOptions $selectionOptions): void {
		$this->setSelectionOptions(json_encode($selectionOptions->jsonSerialize()));
		$this->setSelectionDefault($selectionOptions->defaultSerialized());
	}

	public function getSelectionOptionsArray(): ?array {
		return $this->getSelectionOptionsCollection()->jsonSerialize();
	}

	/**
	 * @psalm-return TablesColumn
	 */
	public function jsonSerialize(): array {
		$serialized = [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'tableId' => $this->tableId,
			'title' => $this->title,
			'technicalName' => $this->technicalName,
			'createdBy' => $this->createdBy,
			'createdByDisplayName' => $this->createdByDisplayName,
			'createdAt' => $this->createdAt,
			'lastEditBy' => $this->lastEditBy,
			'lastEditByDisplayName' => $this->lastEditByDisplayName,
			'lastEditAt' => $this->lastEditAt,
			'viewColumnInformation' => $this->viewColumnInformation?->jsonSerialize(),
			'type' => $this->type,
			'subtype' => $this->subtype,
			'baseSubtype' => $this->getBaseSubtype(),
			'broken' => $this->broken,
			'mandatory' => $this->mandatory,
			'description' => $this->description,

			// type number
			'numberDefault' => $this->numberDefault,
			'numberMin' => $this->numberMin,
			'numberMax' => $this->numberMax,
			'numberDecimals' => $this->numberDecimals,
			'numberPrefix' => $this->numberPrefix,
			'numberSuffix' => $this->numberSuffix,

			// type text
			'textDefault' => $this->textDefault,
			'textAllowedPattern' => $this->textAllowedPattern,
			'textMaxLength' => $this->textMaxLength,
			'textUnique' => $this->textUnique,

			// type selection
			'selectionOptions' => $this->getSelectionOptionsArray(),
			'selectionDefault' => $this->selectionDefault,

			// type datetime
			'datetimeDefault' => $this->datetimeDefault,

			// type usergroup
			'usergroupDefault' => $this->getUsergroupDefaultArray(),
			'usergroupMultipleItems' => $this->usergroupMultipleItems,
			'usergroupSelectUsers' => $this->usergroupSelectUsers,
			'usergroupSelectGroups' => $this->usergroupSelectGroups,
			'usergroupSelectTeams' => $this->usergroupSelectTeams,
			'showUserStatus' => $this->showUserStatus,
			'customSettings' => $this->getCustomSettingsArray() ?: [],
		];
		if ($this->failingValuesCount !== null) {
			$serialized['failingValuesCount'] = $this->failingValuesCount;
		}
		return $serialized;
	}

	public function getCustomSettingsArray(): array {
		return json_decode((string)$this->customSettings, true) ?: [];
	}

	/**
	 * @throws ValueError
	 */
	public function getType(): string {
		return ColumnType::from($this->type)->value;
	}
}
