#!/usr/bin/env bash
#
# 从 docs/diagrams/mascot-icon.svg 重新生成所有客户端应用图标
# （Flutter iOS / macOS / Web / Windows + HarmonyOS）。
#
# 用法：bash scripts/gen-icons.sh
# 依赖：rsvg-convert（SVG 光栅化）、python3（合成多尺寸 .ico，仅用标准库）
#
# 每个目标都按「原有尺寸 + 原有格式」重写；路径不存在时打印跳过信息后继续，
# 因此脚本可以安全地反复运行，也可在裁剪过的仓库副本上运行。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/docs/diagrams/mascot-icon.svg"

[ -f "$SRC" ] || { echo "错误：找不到源图标 $SRC" >&2; exit 1; }
command -v rsvg-convert >/dev/null 2>&1 || { echo "错误：缺少 rsvg-convert" >&2; exit 1; }
command -v python3       >/dev/null 2>&1 || { echo "错误：缺少 python3" >&2; exit 1; }

# $1=边长(px) $2=目标文件绝对路径
gen_png() {
  local size=$1 path=$2
  if [ ! -e "$path" ]; then
    echo "  跳过（不存在） ${path#"$ROOT"/}"
    return 0
  fi
  rsvg-convert -w "$size" -h "$size" "$SRC" -o "$path"
  printf '  %4sx%-4s %s\n' "$size" "$size" "${path#"$ROOT"/}"
}

# $1=目标 ico 路径 $2..=各帧边长(px)
#
# 各帧先由 rsvg-convert 按原生尺寸渲染，再手工拼 ICO 容器（每帧都是 PNG 负载）。
# 不用 ImageMagick 的 `convert a.png b.png out.ico`：IM 7 会把帧写成未压缩 BMP，
# 256x256 一帧就 270KB，整个 ico 会从 33KB 膨胀到 285KB。
gen_ico() {
  local path=$1; shift
  if [ ! -e "$path" ]; then
    echo "  跳过（不存在） ${path#"$ROOT"/}"
    return 0
  fi
  local tmp pngs=()
  tmp="$(mktemp -d)"
  for s in "$@"; do
    rsvg-convert -w "$s" -h "$s" "$SRC" -o "$tmp/$s.png"
    pngs+=("$tmp/$s.png")
  done
  python3 - "$path" "${pngs[@]}" <<'PY'
import struct, sys
# ICO = ICONDIR(6) + ICONDIRENTRY(16) * n + 各帧负载
out, *frames = sys.argv[1:]
entries, blobs, offset = [], [], 6 + 16 * len(frames)
for f in frames:
    data = open(f, 'rb').read()
    w, h = struct.unpack('>II', data[16:24])      # PNG IHDR 宽高
    entries.append(struct.pack('<BBBBHHII', w % 256, h % 256, 0, 0, 1, 32, len(data), offset))
    blobs.append(data)
    offset += len(data)
with open(out, 'wb') as fp:
    fp.write(struct.pack('<HHH', 0, 1, len(frames)) + b''.join(entries) + b''.join(blobs))
PY
  rm -rf "$tmp"
  echo "  ico($*) ${path#"$ROOT"/}"
}

echo "源图标: ${SRC#"$ROOT"/}"

echo "PNG 图标:"
#   尺寸  目标文件（相对仓库根目录）
while read -r size rel; do
  [ -n "$size" ] || continue
  gen_png "$size" "$ROOT/$rel"
done <<'EOF'
20   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-20x20@1x.png
40   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-20x20@2x.png
60   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-20x20@3x.png
29   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-29x29@1x.png
58   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-29x29@2x.png
87   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-29x29@3x.png
40   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-40x40@1x.png
80   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-40x40@2x.png
120  apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-40x40@3x.png
120  apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-60x60@2x.png
180  apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-60x60@3x.png
76   apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-76x76@1x.png
152  apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-76x76@2x.png
167  apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-83.5x83.5@2x.png
1024 apps/flutter/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-1024x1024@1x.png
16   apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_16.png
32   apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_32.png
64   apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_64.png
128  apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_128.png
256  apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_256.png
512  apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_512.png
1024 apps/flutter/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_1024.png
16   apps/flutter/web/favicon.png
192  apps/flutter/web/icons/Icon-192.png
512  apps/flutter/web/icons/Icon-512.png
192  apps/flutter/web/icons/Icon-maskable-192.png
512  apps/flutter/web/icons/Icon-maskable-512.png
216  apps/harmonyos/AppScope/resources/base/media/app_icon.png
216  apps/harmonyos/entry/src/main/resources/base/media/icon.png
EOF

echo "ICO 图标:"
gen_ico "$ROOT/apps/flutter/windows/runner/resources/app_icon.ico" 16 32 48 256
# 站点 favicon（管理后台 / service 落地页）与客户端图标同源，一并重生成
gen_ico "$ROOT/admin/public/favicon.ico"   16 32 48
gen_ico "$ROOT/service/public/favicon.ico" 16 32 48

# 有意跳过：
#   apps/flutter/ios/Runner/Assets.xcassets/LaunchImage.imageset/*.png
#     —— 启动图占位（当前 1x1），不是应用图标；
#   apps/harmonyos/**/app_icon.png、icon.png 原本为 1x1 占位，
#     已按 HarmonyOS 应用图标规范（216x216）生成，不再是占位尺寸。
echo "完成。"
