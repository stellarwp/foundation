<?php declare(strict_types=1);

use StellarWP\Foundation\Database\Migration\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

return new class extends Migration {
	public function up(Blueprint $schema): void {
		$table = $schema->create('discovery_reports');
		$table->bigIncrements('id');
		$table->string('title', 255);
	}

	public function down(Blueprint $schema): void {
		$schema->drop('discovery_reports');
	}
};
