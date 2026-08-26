#!/usr/bin/env bash
# 推送发布规则：获取最新版本（远程最新 tag 优先，无则读 Cargo workspace）→ patch+1
# → 同步版本文件 → 提交推送 → 按最新版本增量创建 tag + GitHub Release（gh 可用时）。
# 按需求取消"推送后打包应用"步骤。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

# 1. 获取最新版本：优先远程最新 tag，其次 Cargo.toml
latest=$(git ls-remote --tags origin 'v*' | awk '{sub("refs/tags/","",$2); print $2}' | sed 's/^v//' | sort -V | tail -1)
if [ -z "$latest" ]; then
  latest=$(grep -m1 '^version = ' infrastructure/Cargo.toml | sed 's/version = "\(.*\)"/\1/')
fi
new=$(echo "$latest" | awk -F. '{printf "%d.%d.%d", $1, $2, $3+1}')

echo "==> 最新版本 v$latest -> 新版本 v$new"

sed -i "s/^version = \"[0-9.]*\"/version = \"$new\"/" infrastructure/Cargo.toml
sed -i "s/^version: .*/version: ${new}+1/" apps/flutter/pubspec.yaml
sed -i "s/\"version\": \".*\"/\"version\": \"$new\"/" apps/harmonyos/entry/oh-package.json5

git add infrastructure/Cargo.toml apps/flutter/pubspec.yaml apps/harmonyos/entry/oh-package.json5
git commit -m "chore: release v$new 版本号同步（Cargo/Flutter/HarmonyOS）"

git push origin main
git tag "v$new"
git push origin "v$new"

# 2. 按最新版本增量创建 GitHub Release（gh 可用时）
if command -v gh >/dev/null 2>&1; then
  if gh release view "v$new" >/dev/null 2>&1; then
    echo "==> Release v$new 已存在，跳过"
  else
    gh release create "v$new" --title "v$new" --generate-notes
    echo "==> 已创建 Release v$new"
  fi
else
  echo "==> 未安装 gh CLI，跳过 GitHub Release（仅创建 tag v$new）"
fi

echo "==> 已发布 v$new（取消打包步骤）"
