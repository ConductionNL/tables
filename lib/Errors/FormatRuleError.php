<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Errors;

/**
 * A format rule that cannot be evaluated, such as one with an unknown operation or an invalid
 * pattern. It says nothing about whether the value is valid.
 */
class FormatRuleError extends \Exception {
}
