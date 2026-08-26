#!/usr/bin/env bash
# 推送发布规则：获取当前版本（Cargo workspace 为准）→ patch+1 同步版本文件
# → 提交推送 → 增量打 tag 并推送。按需求取消"推送后打包应用"步骤。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

cur=$(grep -m1 '^version = ' infrastructure/Cargo.toml | sed 's/version = "\(.*\)"/\1/')
new=$(echo "$cur" | awk -F. '{printf "%d.%d.%d", $1, $2, $3+1}')

echo "==> 当前版本 $cur -> 新版本 v$new"

sed -i "s/^version = \"$cur\"/version = \"$new\"/" infrastructure/Cargo.toml
sed -i "s/^version: .*/version: ${new}+1/" apps/flutter/pubspec.yaml
sed -i "s/\"version\": \".*\"/\"version\": \"$new\"/" apps/harmonyos/entry/oh-package.json5

git add infrastructure/Cargo.toml apps/flutter/pubspec.yaml apps/harmonyos/entry/oh-package.json5
git commit -m "chore: release v$new 版本号同步（Cargo/Flutter/HarmonyOS）"

git push origin main
git tag "v$new"
git push origin "v$new"

echo "==> 已发布 v$new（取消打包步骤）"
