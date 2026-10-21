<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Command;

use OCA\Tables\Errors\FormatInUseError;
use OCA\Tables\Service\FormatPackService;
use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveFormatPack extends Command {
	public function __construct(
		private readonly FormatPackService $packService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('tables:formats:remove-pack')
			->setDescription('Remove the formats installed from a format pack. Columns that use them keep their values and are reported as broken.')
			->addArgument('pack', InputArgument::REQUIRED, 'Name of the pack, such as nl')
			->addOption('force', null, InputOption::VALUE_NONE, 'Remove the formats even when columns use them');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = (string)$input->getArgument('pack');
		try {
			$removed = $this->packService->remove($name, null);
		} catch (FormatInUseError $e) {
			if (!$input->getOption('force')) {
				$output->writeln('<error>' . $e->brokenColumnsCount . ' columns use formats of this pack and will be broken. Run again with --force to remove it.</error>');
				return 1;
			}
			$removed = $this->packService->remove($name, $e->brokenColumnsCount);
		} catch (Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln($removed === [] ? 'No formats of the pack ' . $name . ' are installed.' : 'Removed: ' . implode(', ', $removed));
		return 0;
	}
}
