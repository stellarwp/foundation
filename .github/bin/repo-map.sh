#!/usr/bin/env bash

# Builds a JSON map of the sub-repos name and directory relative to the "src/" dir
# for use in splitting monorepo packages via GitHub Actions.
#
# @see .github/workflows/monorepo-split.yml
#
# @example
# [
#  {
#      "name": "foundation-container",
#      "directory": "Container"
#  },
#  {
#      "name": "foundation-log",
#      "directory": "Log"
#  },
#  etc...
# ]

# Check if $GITHUB_WORKSPACE is defined
if [ -z "$GITHUB_WORKSPACE" ]; then
    # If not defined, assign a specific relative path
    root=$( git rev-parse --show-toplevel )
else
    # If defined, use the GitHub workspace as the root directory
    root="$GITHUB_WORKSPACE"
fi

# Use jq to generate the JSON array directly without line breaks
packages_json=$(find "$root/src" -name composer.json -print0 |
    while IFS= read -r -d $'\0' file; do
        # Extract the package name and directory
        package_name=$(jq -r '.name' < "$file" | sed 's/stellarwp\///')
        relative_directory=$(realpath --relative-to="$root/src" "$(dirname "$file")")

        # Build the JSON object for each package
        jq -n --arg name "$package_name" --arg directory "$relative_directory" '{name: $name, directory: $directory}'
    done | jq -s -c '.')

# Print the JSON to the standard output without line breaks
echo -n "$packages_json"
