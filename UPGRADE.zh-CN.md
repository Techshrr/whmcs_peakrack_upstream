# 升级说明

本文档用于升级 PeakRack 上下游 WHMCS 对接模块。

## 升级前准备

1. 备份上下游 WHMCS 文件。
2. 备份上下游 WHMCS 数据库。
3. 记录当前安装的模块版本。
4. 以受保护方式记录上游 API Key 绑定关系，不要把 API Secret 写入普通备忘文件。
5. 阅读 [CHANGELOG.md](CHANGELOG.md)。
6. 替换文件期间暂停上游 Worker 和下游同步 Cron。

## 升级步骤

1. 从官方仓库下载目标版本：

   https://github.com/Techshrr/whmcs_peakrack_upstream

2. 仅替换对应版本的两个模块目录：

   - 上游：`/modules/addons/peakrack_upstream_api/`
   - 下游：`/modules/servers/peakrackupstream/`

3. 登录上游 WHMCS 后台并打开 Addon Module。
4. 检查 Addon 配置、API Keys、产品策略、系统健康状态和 Worker 状态。
5. 在下游 WHMCS 检查服务器凭据和产品配置项。
6. 恢复两个 Cron。
7. 恢复正常流量前，执行一次下游连接测试并检查下一次同步结果。

## 数据库变更

`1.0.0` 是首个版本。激活上游 Addon 时会创建模块自有数据表。停用 Addon 时会保留这些表及其中数据。

后续版本如果需要修改数据库结构，将在本文档与 [CHANGELOG.md](CHANGELOG.md) 中明确说明。

## 回滚方法

1. 暂停两个 Cron。
2. 恢复旧版模块目录。
3. 如果本次升级已经修改模块自有表或数据，恢复数据库备份。
4. 如果客户区显示与恢复后的文件不一致，清理 WHMCS 模板缓存。
5. 恢复流量前检查 Addon 操作列表与 WHMCS 活动日志。

除非 API Secret 已泄露，否则不要仅为了文件回滚而轮换或替换生产 API Secret。
