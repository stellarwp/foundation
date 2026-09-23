<?php declare(strict_types=1);

use StellarWP\Foundation\Database\Migration\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

return new class extends Migration {
	public function up(Blueprint $schema): void {
		$schema->table('discovery_reports')->dateTime('published_at', 6)->nullable();
	}

	public function down(Blueprint $schema): void {
		$schema->table('discovery_reports')->dropColumn('published_at');
	}
};
