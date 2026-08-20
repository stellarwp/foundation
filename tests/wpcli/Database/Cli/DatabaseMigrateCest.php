<?php declare(strict_types=1);

use PHPUnit\Framework\Assert;

final class DatabaseMigrateCest
{
	public function _before(WPCLITester $I): void {
		$this->dropTables($I);
	}

	public function _after(WPCLITester $I): void {
		$this->dropTables($I);
	}

	public function test_it_runs_database_migrations_through_wp_cli(WPCLITester $I): void {
		$I->cli(['foundation', 'migrate', '--create-table']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Foundation database tables are ready.');

		$I->cli(['foundation', 'migrate', '--run']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Ran 1 migrations.');

		$I->cli(['foundation', 'migrate']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('2026_06_23_000001_create_foundation_cli_example');
		$I->seeInShellOutput('ran');

		$I->cli(['foundation', 'migrate', '--rollback']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Rolled back 1 migrations.');
	}

	public function test_it_refreshes_and_drops_database_tables_through_wp_cli(WPCLITester $I): void {
		$I->cli(['foundation', 'migrate', '--run']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Ran 1 migrations.');

		$I->cli(['foundation', 'migrate', '--refresh', '--yes']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Rolled back 1 migrations and ran 1 migrations.');

		$I->cli(['foundation', 'migrate', '--drop-store', '--yes']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('The migration ledger was dropped. Application tables were not changed, and shared lock storage remains available.');

		$I->cli([
			'db',
			'query',
			sprintf("SHOW TABLES LIKE '%sfoundation_cli_example'", $this->prefix($I)),
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('foundation_cli_example');

		$I->cli([
			'db',
			'query',
			sprintf("SHOW TABLES LIKE '%sfoundation_cli_locks'", $this->prefix($I)),
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('foundation_cli_locks');

		$I->cli(['foundation', 'migrate']);
		$I->seeResultCodeIs(0);
		Assert::assertStringContainsString('The Foundation migration ledger does not exist.', $I->grabLastShellErrorOutput());
	}

	public function test_it_warns_when_showing_status_before_tables_exist(WPCLITester $I): void {
		$I->cli(['foundation', 'migrate']);
		$I->seeResultCodeIs(0);
		Assert::assertStringContainsString('The Foundation migration ledger does not exist.', $I->grabLastShellErrorOutput());
	}

	private function dropTables(WPCLITester $I): void {
		$prefix = $this->prefix($I);

		$I->cli([
			'db',
			'query',
			sprintf(
				'DROP TABLE IF EXISTS %sfoundation_cli_migrations, %sfoundation_cli_locks, %sfoundation_cli_example',
				$prefix,
				$prefix,
				$prefix
			),
		]);
		$I->seeResultCodeIs(0);
	}

	private function prefix(WPCLITester $I): string {
		$I->cli(['db', 'prefix']);
		$I->seeResultCodeIs(0);

		return trim($I->grabLastShellOutput());
	}
}
