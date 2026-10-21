<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Command;

use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Service\FormatPackService;
use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListFormatPacks extends Command {
	public function __construct(
		private readonly FormatPackService $packService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('tables:formats:packs')
			->setDescription('List the format packs Tables ships and how much of each is installed.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$packs = $this->packService->listShippedPacks();
		} catch (BadRequestError|Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$table = new Table($output);
		$table->setHeaders(['Pack', 'Label', 'Version', 'Installed', 'Update available']);
		foreach ($packs as $pack) {
			$table->addRow([$pack['name'], $pack['label'], $pack['version'], $pack['installedCount'] . ' of ' . $pack['formatsCount'], $pack['updateAvailable'] ? 'yes' : 'no']);
		}
		$table->render();
		return 0;
	}
}
