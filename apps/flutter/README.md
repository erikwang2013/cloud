# CloudPlatform Flutter 客户端

CloudPlatform 云资源交易平台的 Flutter 客户端，支持 iOS / Android / Web / Linux / macOS / Windows 六平台。

## 环境要求

- Flutter SDK（stable 渠道，约束见 `pubspec.yaml`）
- 后端服务已部署（安装方式见项目根 [README](../../README.md) 一键安装 / Docker 部署）

## 构建与运行

```bash
cd apps/flutter
flutter pub get          # 安装依赖
flutter run              # 调试运行
flutter build apk        # Android 打包
flutter build ios        # iOS 打包（需 macOS + Xcode）
flutter build web        # Web 打包
flutter build macos / linux / windows   # 桌面端打包
```

## 使用说明

- 注册 / 登录后选购产品、下单支付（Stripe），资源自动交付后在「我的资源」查看与续费
- API 服务地址配置在 `lib/core/network/api_client.dart` 的 `ApiClient.baseUrl`（默认 `https://api.example.com/api`），按需修改
- 详细接口说明见 [docs/api-reference.md](../../docs/api-reference.md)
