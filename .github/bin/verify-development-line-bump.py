#!/usr/bin/env python3
"""Verify that staged composer.json changes are exactly the next development line bump.

Usage: verify-development-line-bump.py <next-alias> <file>...

Run after `git apply --index` of the bump patch. Each file's staged content is compared with HEAD:
the only differences allowed are Foundation package constraints in require/require-dev set to
the expected "^X.Y" and extra.branch-alias.dev-main set to the expected "X.Y.x-dev". Anything else,
including invalid JSON or added/removed keys, fails.
"""

import copy
import json
import re
import subprocess
import sys

FOUNDATION_PACKAGE = re.compile(r"^stellarwp/foundation(-[a-z0-9]+)*$")
REQUIRE_SECTIONS = ("require", "require-dev")


def git_json(revision_path):
    content = subprocess.run(["git", "show", revision_path], check=True, capture_output=True).stdout
    return json.loads(content)


def verify(path, alias, constraint):
    before = git_json(f"HEAD:{path}")
    after = git_json(f":{path}")  # staged version
    errors = []
    changed = False

    # Copy "after" and put back the "before" values of the allowed keys; what remains must be identical.
    normalized = copy.deepcopy(after)

    for section in REQUIRE_SECTIONS:
        old_requires = before.get(section)
        new_requires = after.get(section)
        if not isinstance(old_requires, dict) or not isinstance(new_requires, dict):
            continue
        for package, version in new_requires.items():
            if FOUNDATION_PACKAGE.match(package) and package in old_requires and version != old_requires[package]:
                changed = True
                if version != constraint:
                    errors.append(f'{section}."{package}" is "{version}", expected "{constraint}"')
                normalized[section][package] = old_requires[package]

    old_alias = before.get("extra", {}).get("branch-alias", {}).get("dev-main")
    new_alias = after.get("extra", {}).get("branch-alias", {}).get("dev-main")
    if old_alias is not None and new_alias is not None and new_alias != old_alias:
        changed = True
        if new_alias != alias:
            errors.append(f'extra.branch-alias.dev-main is "{new_alias}", expected "{alias}"')
        normalized["extra"]["branch-alias"]["dev-main"] = old_alias

    if normalized != before:
        errors.append("contains changes other than Foundation constraints and the dev-main branch alias")
    elif not changed:
        errors.append("is in the patch but has no version changes")

    return errors


def main(argv):
    if len(argv) < 3:
        print(__doc__.strip().splitlines()[2], file=sys.stderr)
        return 2

    alias, files = argv[1], argv[2:]
    match = re.fullmatch(r"([0-9]+)\.([0-9]+)\.x-dev", alias)
    if not match:
        print(f'Unexpected development alias "{alias}".')
        return 1
    constraint = f"^{match.group(1)}.{match.group(2)}"

    failed = False
    for path in files:
        try:
            errors = verify(path, alias, constraint)
        except (subprocess.CalledProcessError, json.JSONDecodeError, AttributeError, KeyError, TypeError) as error:
            errors = [f"could not be verified: {error}"]
        for message in errors:
            print(f"{path} {message}")
            failed = True

    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
