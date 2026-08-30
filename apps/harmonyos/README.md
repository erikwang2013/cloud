# CloudPlatform HarmonyOS 客户端

CloudPlatform 云资源交易平台的 HarmonyOS 原生客户端（ArkTS / ArkUI）。

## 环境要求

- DevEco Studio 5.x + HarmonyOS SDK（API 12+，见 `build-profile.json5`）
- 后端服务已部署（安装方式见项目根 [README](../../README.md) 一键安装 / Docker 部署）

## 构建与运行

使用 DevEco Studio 打开 `apps/harmonyos` 目录，等待工程同步完成后：

- **运行**：选择设备 / 模拟器后点击 Run（`entry` 模块）
- **打包**：Build → Build Hap(s)/App(s)

## 使用说明

- 注册 / 登录后选购产品、下单支付（Stripe），资源自动交付后在「我的资源」查看与续费
- 详细接口说明见 [docs/api-reference.md](../../docs/api-reference.md)
