<?php declare(strict_types=1);

use PHPUnit\Framework\Assert;

/**
 * Exercise migration commands through their registered WP-CLI synopses.
 */
final class DatabaseMigrateCest
{
	/**
	 * Start each command scenario with empty storage.
	 */
	public function _before(WPCLITester $I): void {
		$this->dropTables($I);
	}

	/**
	 * Remove application and ledger tables after each scenario.
	 */
	public function _after(WPCLITester $I): void {
		$this->dropTables($I);
	}

	/**
	 * Inspect, preview, execute, and reverse one migration.
	 */
	public function test_it_previews_runs_and_rolls_back_migrations(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('pending');
		$I->seeInShellOutput("20260623000001\t20260623000001\tpending");
		$I->cli([
			'foundation',
			'migrate:run',
			'--dry-run',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('CREATE TABLE');
		$I->seeInShellOutput('Previewed 1 migration steps.');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
		$I->cli([
			'foundation',
			'migrate:run',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 1 migration steps.');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('20260623000001');
		$I->seeInShellOutput('applied');
		$I->cli([
			'foundation',
			'migrate:rollback',
			'--step=1',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Down 20260623000001');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
	}

	/**
	 * Apply a target version and refresh its complete history.
	 */
	public function test_it_refreshes_and_targets_versions(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:run',
			'--to=20260623000001',
		]);
		$I->seeResultCodeIs(0);
		$I->cli([
			'foundation',
			'migrate:refresh',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 2 migration steps.');
		$I->cli([
			'foundation',
			'migrate:rollback',
			'--to=0',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
	}

	/**
	 * Reset reverses applied work, preserves WordPress, and never runs pending work.
	 */
	public function test_reset_reverses_migrations_without_reapplying_them(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:reset',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 0 migration steps.');
		$I->cli([
			'foundation',
			'migrate:run',
		]);
		$I->seeResultCodeIs(0);
		$I->cli([
			'foundation',
			'migrate:reset',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Down 20260623000001');
		$I->seeInShellOutput('Completed 1 migration steps.');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('pending');
		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			$table = $wpdb->get_var($wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like($wpdb->prefix . 'foundation_cli_example'),
			));
			echo $table === null ? 'Application table removed' : 'Unexpected table';
			echo get_option('siteurl') ? ' WordPress preserved' : ' WordPress missing';
			PHP,
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Application table removed WordPress preserved');
		$I->cli([
			'foundation',
			'migrate:reset',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 0 migration steps.');
		$I->cli([
			'foundation',
			'migrate:run',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 1 migration steps.');
	}

	/**
	 * A rollback target must not trigger forward execution.
	 */
	public function test_target_rollback_does_not_apply_a_pending_target(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:rollback',
			'--to=20260623000001',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 0 migration steps.');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
	}

	/**
	 * Declined full reversals preserve history while previews never prompt.
	 */
	public function test_full_reversals_require_confirmation_but_previews_do_not(WPCLITester $I): void {
		foreach ([
			'migrate:run',
			'migrate:rollback',
		] as $operation) {
			$I->cli([
				'foundation',
				'migrate:run',
			]);
			$I->seeResultCodeIs(0);
			$I->cli([
				'foundation',
				$operation,
				'--to=0',
			], null, "n\n");
			$I->seeInShellOutput('Roll back all migrations?');
			$I->cli([
				'foundation',
				'migrate:status',
			]);
			$I->seeInShellOutput('applied');
			$I->cli([
				'foundation',
				'migrate:run',
				'--to=0',
				'--dry-run',
			]);
			$I->seeResultCodeIs(0);
			$I->seeInShellOutput('Previewed 1 migration steps.');
			$I->cli([
				'foundation',
				$operation,
				'--to=0',
				'--yes',
			]);
			$I->seeResultCodeIs(0);
			$I->seeInShellOutput('Completed 1 migration steps.');
			$I->cli([
				'foundation',
				'migrate:status',
			]);
			$I->seeInShellOutput('pending');
		}
	}

	/**
	 * Commands reject arguments and flags outside their own synopsis.
	 */
	public function test_unknown_or_wrong_command_options_are_rejected(WPCLITester $I): void {
		foreach ([
			[
				'migrate:status',
				'--dry-run',
			],
			[
				'migrate:status',
				'unexpected',
			],
			[
				'migrate:run',
				'--step=1',
			],
			[
				'migrate:rollback',
				'--dry-run',
			],
			[
				'migrate:reset',
				'--step=1',
			],
			[
				'migrate:reset',
				'--to=0',
			],
			[
				'migrate:reset',
				'--dry-run',
			],
			[
				'migrate:refresh',
				'--to=0',
			],
			[
				'migrate:mark-applied',
				'--all',
				'--dry-run',
			],
			[
				'migrate:mark-pending',
				'20260623000001',
				'--all',
			],
			[
				'migrate:run',
				'--unknown-option',
			],
		] as $args) {
			$I->cli([
				'foundation',
				...$args,
			]);
			$I->seeResultCodeIs(1);
			Assert::assertStringContainsString(
				$args[0] === 'migrate:status'
					? 'migrate:status does not accept arguments or options.'
					: 'Parameter errors:',
				$I->grabLastShellErrorOutput(),
			);
		}

		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('pending');
	}

	/**
	 * Rollback validates positive steps and exclusive target selection.
	 */
	public function test_rollback_rejects_invalid_step_and_target_combinations(WPCLITester $I): void {
		foreach ([
			[
				'--step=0',
			],
			[
				'--step=-1',
			],
			[
				'--step=abc',
			],
			[
				'--step=1',
				'--to=0',
			],
		] as $args) {
			$I->cli([
				'foundation',
				'migrate:rollback',
				...$args,
				'--yes',
			]);
			$I->seeResultCodeIs(1);
			Assert::assertStringContainsString('--step', $I->grabLastShellErrorOutput());
		}

		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
	}

	/**
	 * Adopt an existing table and prove history removal preserves its rows.
	 */
	public function test_marking_adopts_existing_work_and_removes_only_history(WPCLITester $I): void {
		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			$wpdb->query("CREATE TABLE {$wpdb->prefix}foundation_cli_example (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
			) ENGINE=InnoDB");
			$wpdb->insert($wpdb->prefix . 'foundation_cli_example', [
				'id' => 42,
			]);
			PHP,
		]);
		$I->seeResultCodeIs(0);
		$I->cli([
			'foundation',
			'migrate:mark-applied',
			'20260623000001',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Recorded 20260623000001 as applied.');
		$I->cli([
			'foundation',
			'migrate:run',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Completed 0 migration steps.');
		$I->cli([
			'foundation',
			'migrate:mark-pending',
			'20260623000001',
			'--yes',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('Removed the applied record for 20260623000001.');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			echo $wpdb->get_var("SELECT id
				FROM {$wpdb->prefix}foundation_cli_example");
			PHP,
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('42');
	}

	/**
	 * Confirm bulk bookkeeping without executing the pending create declaration.
	 */
	public function test_mark_all_requires_confirmation_and_skips_application_schema(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:mark-applied',
			'--all',
		], null, "n\n");
		$I->seeInShellOutput('20260623000001 (pending)');
		$I->seeInShellOutput('Record all pending migrations as applied?');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');

		for ($attempt = 0; $attempt < 2; $attempt++) {
			$I->cli([
				'foundation',
				'migrate:mark-applied',
				'--all',
				'--yes',
			]);
			$I->seeResultCodeIs(0);
			$I->seeInShellOutput('All registered migrations are recorded as applied.');
		}

		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			$table = $wpdb->get_var($wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like($wpdb->prefix . 'foundation_cli_example'),
			));
			echo $table === null ? 'No application table created' : 'Unexpected table';
			PHP,
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('No application table created');
	}

	/**
	 * Invalid history requests must leave the migration pending.
	 */
	public function test_history_marking_rejects_ambiguous_or_unknown_requests(WPCLITester $I): void {
		foreach ([
			[
				'migrate:status',
				'--all',
			],
			[
				'migrate:mark-applied',
			],
			[
				'migrate:mark-applied',
				'unknown',
			],
			[
				'migrate:mark-applied',
				'20260623000001',
				'--all',
			],
			[
				'migrate:mark-pending',
			],
			[
				'migrate:mark-pending',
				'--all',
			],
			[
				'migrate:mark-pending',
				'0',
			],
			[
				'migrate:mark-applied',
				'--all',
				'--run',
			],
			[
				'migrate:mark-applied',
				'--all',
				'--dry-run',
			],
			[
				'migrate:mark-pending',
				'20260623000001',
				'--to=0',
			],
		] as $args) {
			$I->cli([
				'foundation',
				...$args,
				'--yes',
			]);
			$I->seeResultCodeIs(1);
		}

		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('pending');
	}

	/**
	 * Single-record marking leaves history unchanged when confirmation is declined.
	 */
	public function test_declining_single_history_operations_preserves_status(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:mark-applied',
			'20260623000001',
		], null, "n\n");
		$I->seeInShellOutput('Record 20260623000001 as applied?');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('pending');
		$I->cli([
			'foundation',
			'migrate:run',
		]);
		$I->seeResultCodeIs(0);
		$I->cli([
			'foundation',
			'migrate:mark-pending',
			'20260623000001',
		], null, "n\n");
		$I->seeInShellOutput('Remove the applied record for 20260623000001?');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('applied');
	}

	/**
	 * Declined reset and refresh commands preserve application rows and history.
	 */
	public function test_declining_reset_and_refresh_preserves_application_rows(WPCLITester $I): void {
		$I->cli([
			'foundation',
			'migrate:run',
		]);
		$I->seeResultCodeIs(0);
		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			$wpdb->insert($wpdb->prefix . 'foundation_cli_example', [
				'id' => 42,
			]);
			PHP,
		]);
		$I->seeResultCodeIs(0);

		foreach ([
			'migrate:reset'   => 'Roll back all migrations?',
			'migrate:refresh' => 'Roll back and rerun all migrations?',
		] as $command => $confirmation) {
			$I->cli([
				'foundation',
				$command,
			], null, "n\n");
			$I->seeInShellOutput($confirmation);
		}

		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			echo $wpdb->get_var("SELECT id
				FROM {$wpdb->prefix}foundation_cli_example");
			PHP,
		]);
		$I->seeResultCodeIs(0);
		$I->seeInShellOutput('42');
		$I->cli([
			'foundation',
			'migrate:status',
		]);
		$I->seeInShellOutput('applied');
	}

	/**
	 * Each registered command exposes its own help and relevant options.
	 */
	public function test_each_command_has_its_own_help_synopsis(WPCLITester $I): void {
		foreach ([
			'migrate:status'       => [],
			'migrate:run'          => [
				'--to',
				'--dry-run',
			],
			'migrate:rollback'     => [
				'--step',
				'--to',
			],
			'migrate:reset'        => [
				'--yes',
			],
			'migrate:refresh'      => [
				'--yes',
			],
			'migrate:mark-applied' => [
				'<id>',
				'--all',
			],
			'migrate:mark-pending' => [
				'<id>',
			],
		] as $command => $options) {
			$I->cli([
				'help',
				'foundation',
				$command,
			]);
			$I->seeResultCodeIs(0);
			$I->seeInShellOutput('SYNOPSIS');
			$I->seeInShellOutput('wp foundation ' . $command);

			foreach ($options as $option) {
				$I->seeInShellOutput($option);
			}
		}
	}

	/**
	 * The replaced multipurpose command and its flag form are not retained as aliases.
	 */
	public function test_old_migrate_command_is_not_registered(WPCLITester $I): void {
		foreach ([
			[],
			[
				'--run',
			],
			[
				'mark-applied',
				'20260623000001',
				'--yes',
			],
		] as $args) {
			$I->cli([
				'foundation',
				'migrate',
				...$args,
			]);
			$I->seeResultCodeIs(1);
			Assert::assertStringContainsString('is not a registered subcommand', $I->grabLastShellErrorOutput());
		}
	}

	private function dropTables(WPCLITester $I): void {
		$I->cli([
			'eval',
			<<<'PHP'
			global $wpdb;
			$prefix = $wpdb->prefix;

			if ($wpdb->query("DROP TABLE IF EXISTS {$prefix}foundation_cli_migrations, {$prefix}foundation_cli_example") === false) {
				WP_CLI::error($wpdb->last_error);
			}
			PHP,
		]);
		$I->seeResultCodeIs(0);
	}
}
