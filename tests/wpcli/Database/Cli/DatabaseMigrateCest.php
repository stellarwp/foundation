<?php declare(strict_types=1);

final class DatabaseMigrateCest
{
	public function _before(WPCLITester $I): void {
		$this->dropTables($I);
	}

	public function _after(WPCLITester $I): void {
		$this->dropTables($I);
	}

	public function test_it_previews_runs_and_rolls_back_migrations(WPCLITester $I): void {
		$I->cli(['foundation', 'migrate']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('pending');
		$I->cli(['foundation', 'migrate', '--run', '--dry-run']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('CREATE TABLE');
		$I->seeInShellOutput('Previewed 1 migration steps.');
		$I->cli(['foundation', 'migrate']);
		$I->seeInShellOutput('pending');
		$I->cli(['foundation', 'migrate', '--run']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 1 migration steps.');
		$I->cli(['foundation', 'migrate']);
		$I->seeInShellOutput('20260623000001');
		$I->seeInShellOutput('applied');
		$I->cli(['foundation', 'migrate', '--rollback', '--step=1']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Down 20260623000001');
		$I->cli(['foundation', 'migrate']);
		$I->seeInShellOutput('pending');
	}

	public function test_it_refreshes_and_targets_versions(WPCLITester $I): void {
		$I->cli(['foundation', 'migrate', '--run', '--to=20260623000001']);
		$I->seeResultCodeIs(0);
		$I->cli(['foundation', 'migrate', '--refresh', '--yes']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 2 migration steps.');
		$I->cli(['foundation', 'migrate', '--rollback', '--to=0']);
		$I->seeResultCodeIs(0);
		$I->cli(['foundation', 'migrate']);
		$I->seeInShellOutput('pending');
	}

	public function test_invalid_options_fail_before_migration_execution(WPCLITester $I): void {
		foreach ([['--run', '--refresh'], ['--dry-run'], ['--rollback', '--step=0'], ['--rollback', '--step=1', '--to=0']] as $flags) {
			$I->cli(['foundation', 'migrate', ...$flags]);
			$I->seeResultCodeIs(1);
		}
		$I->cli(['foundation', 'migrate']);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('pending');
	}

	private function dropTables(WPCLITester $I): void {
		$I->cli(['eval', <<<'PHP'
			global $wpdb;
			$prefix = $wpdb->prefix;
			if ($wpdb->query("DROP TABLE IF EXISTS {$prefix}foundation_cli_migrations, {$prefix}foundation_cli_example") === false) {
				WP_CLI::error($wpdb->last_error);
			}
			PHP]);
		$I->seeResultCodeIs(0);
	}
}
