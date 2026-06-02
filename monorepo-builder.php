<?php declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushNextDevReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushTagReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetCurrentMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetNextMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\TagVersionReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateBranchAliasReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateReplaceReleaseWorker;
use Symplify\MonorepoBuilder\ValueObject\Option;

return static function (MBConfig $config): void {
	$params = $config->parameters();

	// Exclude any non-monorepo repos with the same vendor.
	$params->set(Option::EXCLUDE_PACKAGE_VERSION_CONFLICTS, [
		'stellarwp/container-contract',
	]);

	$config->packageDirectories([__DIR__ . '/src']);

	$config->defaultBranch('main');

	// default: "<major>.<minor>-dev".
	$config->packageAliasFormat('<major>.<minor>.x-dev');

	// release workers - in order to execute.
	$config->workers([
		UpdateReplaceReleaseWorker::class,
		SetCurrentMutualDependenciesReleaseWorker::class,
		TagVersionReleaseWorker::class,
		PushTagReleaseWorker::class,
		SetNextMutualDependenciesReleaseWorker::class,
		UpdateBranchAliasReleaseWorker::class,
		PushNextDevReleaseWorker::class,
	]);
};
