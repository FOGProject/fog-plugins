#!/bin/bash
#
# Build the release asset for a tag: fog-plugins-<tag>.tar.gz and its .sha256.
#
# Exists so the modes in the asset are a property of the build rather than of
# whoever ran it. Two ways to get this wrong, and this repository has now hit
# both:
#
#   plain tar        - carries the working tree's modes, so the stray +x that
#                      fogproject's core.fileMode=false never corrected went
#                      straight into every FOG web root;
#   git archive      - carries git's modes, which is better, but its tar writer
#                      hardcodes 0664/0775. Group-writable is a worse thing to
#                      unpack into a web root than a pointless +x.
#
# So: git archive for the content (it honours export-ignore and cannot drift
# from the tag), then explicit modes, then a deterministic tar.
#
# Usage: ./make-release.sh <tag> [outdir]
#
set -euo pipefail

tag="${1:?usage: ${0##*/} <tag> [outdir]}"
out="$(cd "${2:-.}" && pwd)"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

git -C "$repo" rev-parse -q --verify "refs/tags/$tag" >/dev/null \
    || { echo "no such tag: $tag" >&2; exit 1; }

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

git -C "$repo" archive --format=tar "$tag" | tar -x -C "$stage"

find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +

# Sorted, no owner, fixed mtime: two builds of the same tag produce the same
# bytes, so the published sha256 means something.
tarball="$out/fog-plugins-${tag}.tar.gz"
tar -czf "$tarball" \
    --sort=name --owner=0 --group=0 --numeric-owner \
    --mtime="@$(git -C "$repo" log -1 --format=%ct "$tag")" \
    -C "$stage" .
( cd "$out" && sha256sum "fog-plugins-${tag}.tar.gz" > "fog-plugins-${tag}.tar.gz.sha256" )

echo "$tarball"
