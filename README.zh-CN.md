# PeakRack 上下游 WHMCS 对接模块

通过签名上游 API 与下游 Provisioning Module 实现 WHMCS 对 WHMCS 的经销商开通流程。

> 官方仓库：https://github.com/Techshrr/whmcs_peakrack_upstream
> 许可证：Apache-2.0

## 项目说明

本仓库包含两个 WHMCS 模块：

- `peakrack_upstream_api`：安装在上游 WHMCS 的 Addon Module，用于管理经销商 API Key、产品策略、WHMCS Credit 计费、幂等操作和签名 JSON API。
- `peakrackupstream`：安装在下游 WHMCS 的 Provisioning Module，用于将下游服务操作映射到上游 API，并同步已经确认的服务状态。

上游 WHMCS 负责经销商 Credit、上游订单、账单、服务和供应商交付状态。下游 WHMCS 负责自己的客户与计费。下游终端客户资料不会同步到上游。

## 已实现功能

- HMAC-SHA256 签名、时间戳、Nonce 防重放、可选 IP 白名单和每个 API Key 的速率限制。
- 一个 API Key 绑定一个上游经销商客户和一个固定的下游实例 ID。
- 产品、周期、操作、位置、系统模板、交付字段、SSO 域名和 destroy 权限策略。
- 开通、续费和套餐变更使用上游 WHMCS 原生 Credit。
- 支持同步与队列混合处理、幂等重试、状态确认、补偿和人工复核。
- 通过 WHMCS Local API 和上游产品已绑定的 Provisioning Module 执行生命周期操作。
- 下游支持开通、暂停、解除暂停、终止、续费、套餐变更、状态同步、只读客户区和可选 SSO。
- 操作事件写入和下游 `logModuleCall` 前执行递归脱敏。
- 独立的上游 Worker 与下游同步 CLI Cron。

## 环境要求

- 上下游均使用 WHMCS `9.0.x`。
- PHP `8.2` 或更高版本，主要开发环境为 PHP 8.3。
- 下游 PHP 已启用 cURL 扩展。
- 上游 API 使用有效 HTTPS 证书。
- 上游授权产品已经绑定 Provisioning Module，并配置经销商币种价格。
- 每个经销关系使用专用上游经销商客户，且具有足够 WHMCS Credit。
- 上游 WHMCS 必须关闭 **Automatic Credit Use**。
- 上游 WHMCS 必须关闭 **Credit on Downgrade**。
- 上游配置一个不会自动扣款的订单支付方式；默认值为 `mailin`，管理员需要确认该支付方式适用于当前安装。

本项目发布明文 PHP 源码，不要求 ionCube。

## 安装方法

1. 备份上下游 WHMCS 文件和数据库。
2. 将上游模块复制到上游 WHMCS：

   `/modules/addons/peakrack_upstream_api/`

3. 将下游模块复制到下游 WHMCS：

   `/modules/servers/peakrackupstream/`

4. 在上游 WHMCS 后台的 Addon Modules 中启用 **PeakRack Upstream API**。
5. 确认 `Automatic Credit Use` 与 `Credit on Downgrade` 均已关闭；任一设置开启时，模块会拒绝激活。
6. 配置 Addon 使用的订单支付方式和 Worker 批量大小。
7. 为经销商客户创建 API Key，并立即保存只显示一次的 API Secret。
8. 为 API Key 创建至少一条产品策略。上游产品必须有经销商币种价格，并已绑定 Provisioning Module。
9. 在下游 WHMCS 创建使用 **PeakRack Upstream** 模块的服务器，再将其分配给下游产品。
10. 配置下文两个 Cron。

模块不会修改 WHMCS 核心文件。

## 上游 Addon 配置

| 配置项 | 说明 | 默认值 |
|---|---|---|
| Order Payment Method | API 创建上游订单时使用的支付方式 | `mailin` |
| Worker Batch Size | 每分钟 Worker 最多领取的操作数量 | `25` |

Addon 后台提供 API Keys、产品策略、操作记录、托管服务和系统健康页面。API Secret 仅在创建或轮换时显示。空 IP 白名单必须由管理员明确确认。

## 下游服务器配置

| WHMCS 服务器字段 | 填写内容 |
|---|---|
| Hostname | 不包含协议和路径的上游 WHMCS 域名 |
| IP Address | 可选，不用于构造 API 地址 |
| Username | 上游 API Public Key |
| Password | 上游 API Secret |
| Access Hash | 可选的上游 WHMCS 基础路径，例如 `/billing` |
| Secure | 必须启用 |
| Port | HTTPS 端口，通常为 `443` |

每个下游实例必须使用独立 API Key。

## 下游产品配置

| 配置项 | 说明 | 默认值 |
|---|---|---|
| Upstream Product ID | 上游 API 策略授权的产品 ID | 必填 |
| Upstream Billing Cycle | 授权的上游周期，或使用下游服务周期 | `auto` |
| Upstream Location | 可选，由上游策略管理的位置标识 | 空 |
| Default OS Template | 可选，由上游策略管理的系统标识 | 空 |
| Terminate Mode | 到期取消，或在策略允许时立即销毁 | `cancel_only` |
| Request Timeout | 签名 API 请求超时，限制为 5 至 120 秒 | `30` |

## Cron 配置

上游 Worker 每分钟运行一次：

```cron
* * * * * php /path/to/whmcs/modules/addons/peakrack_upstream_api/cron/worker.php
```

下游服务同步每五分钟运行一次：

```cron
*/5 * * * * php /path/to/whmcs/modules/servers/peakrackupstream/cron/sync.php
```

两个脚本都拒绝浏览器执行。下游同步使用非阻塞进程锁，并且只在收到有效、已完成的上游响应后更新本地服务状态。

## 运行说明

- 开通使用确定性幂等键；尚未完成的生命周期操作会复用已保存的键。
- 续费幂等键包含目标续费边界，套餐变更幂等键包含目标参数哈希。
- `cancel_only` 只安排取消，不自动退还 Credit。
- `destroy` 必须同时由下游产品配置和上游产品策略允许。
- 续费由上游 API 控制，不应对 API 托管服务启用冲突的独立自动化。
- 下游客户区只显示缓存状态、主 IP、安全 HTTPS 面板地址、最后同步时间和可选 SSO。
- 第一版不提供客户自行暂停、解除暂停、终止或变更套餐的按钮。
- Addon 停用时保留其数据库表和数据。

## 验证边界

单元测试和模拟契约测试可以验证模块逻辑、签名、响应结构与幂等行为，但不会创建真实供应商计费订单。生产启用前，必须在隔离的 WHMCS 环境中使用真实上游 Provisioning Module 验证完整流程，不能仅根据模拟测试声明供应商兼容性。

## API 文档

请查看 [docs/api-v1.md](docs/api-v1.md)。

## 发布包

运行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1
```

脚本会运行测试与语法检查、扫描已跟踪文件中的凭据特征、分别生成上下游 ZIP，并写入 SHA-256 校验文件。

## 升级说明

请查看 [UPGRADE.zh-CN.md](UPGRADE.zh-CN.md)。

## 英文文档

请查看 [README.md](README.md)。

## 安全说明

请勿在日志、Issue 或仓库中提交生产 API Key、API Secret、数据库凭据、WHMCS 授权信息、客户数据、供应商凭据或私有签名密钥。

安全问题报告方式请查看 [SECURITY.md](SECURITY.md)。

## 许可证

本项目基于 Apache-2.0 发布。完整条款请查看 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。

两个可部署模块目录内也包含相同的许可证与声明文件。
