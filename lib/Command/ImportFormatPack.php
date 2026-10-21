<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Command;

use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Service\FormatPackService;
use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportFormatPack extends Command {
	public function __construct(
		private readonly FormatPackService $packService,
		private readonly FormatPackReader $packReader,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('tables:formats:import-pack')
			->setDescription('Import a format pack Tables ships, or a pack file. Formats that would change an installed one need --keep or --replace.')
			->addArgument('pack', InputArgument::REQUIRED, 'Name of a shipped pack, such as nl, or the path to a pack file')
			->addOption('keep', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Keep the installed format with this technical name')
			->addOption('replace', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Replace the installed format with this technical name')
			->addOption('keep-all', null, InputOption::VALUE_NONE, 'Keep every installed format that would change')
			->addOption('replace-all', null, InputOption::VALUE_NONE, 'Replace every installed format that would change');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$packArgument = (string)$input->getArgument('pack');
		try {
			$pack = is_file($packArgument) ? $this->packReader->readFile($packArgument) : $this->packReader->readShipped($packArgument);
			$resolutions = $this->resolutions($input, $pack->formats);
			$result = $this->packService->import($pack, $resolutions, null);
		} catch (FormatImportConflictError $e) {
			$output->writeln('<error>These formats would change. Choose with --keep, --replace, --keep-all or --replace-all:</error>');
			foreach ($e->conflicts as $conflict) {
				$output->writeln(sprintf(
					'  %s (%s): installed %s from %s, pack has %s; %d columns use it, %d stored values would fail',
					$conflict['technicalName'],
					$conflict['status'],
					(string)$conflict['installedVersion'],
					(string)$conflict['installedSource'],
					$conflict['version'],
					$conflict['columnsCount'],
					$conflict['failingValuesCount'],
				));
			}
			return 1;
		} catch (BadRequestError|Exception|InternalError $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		foreach (['created' => 'Created', 'replaced' => 'Replaced', 'kept' => 'Kept', 'unchanged' => 'Unchanged', 'skipped' => 'Skipped, as Tables ships them'] as $key => $title) {
			if ($result[$key] !== []) {
				$output->writeln($title . ': ' . implode(', ', $result[$key]));
			}
		}
		return 0;
	}

	/**
	 * @param array<string, mixed> $formats
	 * @return array<string, string>
	 */
	private function resolutions(InputInterface $input, array $formats): array {
		$resolutions = [];
		if ($input->getOption('keep-all') || $input->getOption('replace-all')) {
			$resolution = $input->getOption('replace-all') ? FormatPackService::RESOLUTION_REPLACE : FormatPackService::RESOLUTION_KEEP;
			$resolutions = array_fill_keys(array_keys($formats), $resolution);
		}
		foreach ((array)$input->getOption('keep') as $technicalName) {
			$resolutions[(string)$technicalName] = FormatPackService::RESOLUTION_KEEP;
		}
		foreach ((array)$input->getOption('replace') as $technicalName) {
			$resolutions[(string)$technicalName] = FormatPackService::RESOLUTION_REPLACE;
		}
		return $resolutions;
	}
}
