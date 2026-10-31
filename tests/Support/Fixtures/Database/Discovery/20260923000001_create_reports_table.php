<?php declare(strict_types=1);

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

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
