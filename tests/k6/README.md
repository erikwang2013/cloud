# k6 负载测试

基于 [k6](https://k6.io/) 的 API 负载测试脚本。

## 安装

```bash
# macOS
brew install k6

# Linux
sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt update && sudo apt install k6

# Docker
docker pull grafana/k6
```

## 运行

```bash
# 冒烟测试
k6 run tests/k6/k6-smoke.js

# 产品列表（验证缓存）
k6 run tests/k6/k6-products.js

# 完整下单流程（需 TOKEN 环境变量）
TOKEN=xxx k6 run tests/k6/k6-checkout.js

# 并发压测
k6 run tests/k6/k6-concurrent.js
```

## 环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `BASE_URL` | API 地址 | `http://localhost:8787` |
| `TOKEN` | 认证 Token（checkout 测试必需） | — |
| `VUS` | 并发虚拟用户数（concurrent） | `100` |
| `DURATION` | 压测持续时间 | `60s` |
