<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Helper;

use DateTimeZone;
use OCP\IConfig;

/**
 * The timezone the naive datetimes of the datetime cells are read in, so that every
 * client sees the same instant for the same cell: the instance's `default_timezone`.
 */
class InstanceTimezone {
	private ?DateTimeZone $timezone = null;

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function get(): DateTimeZone {
		if ($this->timezone === null) {
			try {
				$this->timezone = new DateTimeZone($this->config->getSystemValueString('default_timezone', 'UTC'));
			} catch (\Exception) {
				$this->timezone = new DateTimeZone('UTC');
			}
		}
		return $this->timezone;
	}
}
