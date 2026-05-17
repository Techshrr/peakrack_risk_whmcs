# PeakRack Risk for WHMCS

[English](README.md) | [简体中文](README.zh-CN.md)

PeakRack Risk 是一个面向 WHMCS 的订单风险审核插件，用于结账安全确认、订单风险评分、欺诈复核自动化和审计日志记录。

该项目将原本独立运行的结账提醒钩子和欺诈检查钩子整理为标准 WHMCS Addon Module，提供后台配置页面、中英文后台文本、可编辑的结账弹窗内容，以及持久化的风险处理记录。

## 功能特性

- 在 WHMCS 欺诈检查完成后继续计算订单风险分。
- 支持配置人工审核阈值和高风险欺诈阈值。
- 可将达到审核阈值的订单转为 `Pending`，方便人工复核。
- 在明确启用自动处理后，可将高风险订单标记为 `Fraud`。
- 可在结账提交前显示安全确认弹窗。
- 支持服务端确认校验和 nonce 防护，避免只依赖前端状态。
- 弹窗标题、正文、提示项、按钮和校验提示均可在后台修改。
- 支持中文和英文结账弹窗内容。
- 支持中文和英文后台界面文本。
- 保存风险决策记录、审计日志和规则版本快照。
- 通过 WHMCS 每日 Cron 按保留策略清理旧审计日志和规则快照。
- 可控制有多少审计记录镜像到 WHMCS Activity Log。
- 在 WHMCS 后台订单详情页显示 PeakRack Risk 风险面板。
- 提供手动重新评分订单和受控执行订单动作的工具。
- 显示最近 7 天和 30 天的风险处理指标。
- 提供数据库结构、Hook 运行、规则版本和 WHMCS 辅助函数诊断。
- 支持 JSON 配置导入和导出。
- 同一规则版本已经处理过的订单，自动 Hook 会跳过重复执行。
- 停用插件时保留历史审计和决策数据。

## 兼容环境

- WHMCS 9.x
- PHP 8.3
- WHMCS 支持的 MySQL 或 MariaDB

插件使用 WHMCS `Capsule`、`localAPI`、Addon Module 生命周期函数和标准 Hook 注册方式。

## 安装方式

1. 将 `peakrack_risk` 目录上传到 WHMCS：

   ```text
   modules/addons/peakrack_risk
   ```

2. 登录 WHMCS 后台，进入：

   ```text
   System Settings > Addon Modules
   ```

3. 启用 **PeakRack Risk**。

4. 打开：

   ```text
   Addons > PeakRack Risk
   ```

5. 检查阈值、权重、白名单、结账弹窗文案和后台语言设置。

## 配置说明

### 基础控制

- **启用风控引擎**：启用或关闭订单风险评分。
- **启用结账提醒**：在结账页面显示安全确认弹窗。
- **要求服务端确认**：结账提交时必须通过确认字段和 nonce 校验。
- **仅记录日志**：只保存风险决策，不修改订单状态。
- **允许自动执行 FraudOrder**：允许插件对高风险订单调用 WHMCS `FraudOrder`。
- **后台语言**：切换插件后台界面为中文或英文。
- **WHMCS 活动日志**：控制 info、warning、error 或不把插件审计记录镜像到 WHMCS Activity Log。

### 阈值

- **审核阈值**：订单风险分达到该值后转为 `Pending`。
- **欺诈阈值**：订单风险分达到该值后视为高风险。审核阈值会保持不高于欺诈阈值。
- **API 重试次数**：调用 WHMCS `localAPI` 修改订单状态时的重试次数。
- **IP 爆发窗口**：检测同一 IP 重复下单的时间窗口。
- **IP 爆发订单数**：同一 IP 在窗口内达到该订单数后增加风险分。

### 保留策略

- **审计保留天数**：每日清理时删除早于该天数的审计日志。填 `0` 表示不按时间清理。
- **最大审计日志数**：只保留最新的审计日志行数。填 `0` 表示不按数量清理。
- **最大规则版本数**：只保留最新的规则快照版本。填 `0` 表示不按数量清理。

### 名单配置

- 高风险国家
- 可信邮箱域名
- 白名单客户 ID
- 白名单客户组 ID
- 白名单邮箱域名
- 白名单 IP/CIDR
- 白名单产品 ID
- 白名单付款方式，填写 WHMCS 网关模块名称

### 信任策略

- **可信客户天数**：客户账号达到设定天数后应用客户年龄信任权重。填 `0` 表示关闭。
- **可信已付发票数**：客户达到设定已付发票数量后应用已付发票信任权重。填 `0` 表示关闭。
- **信任已验证邮箱**：当 WHMCS 客户记录提供邮箱验证标记时应用邮箱验证信任权重。

### 风险权重

每个风险信号都有独立权重。正数增加风险，负数降低风险。

当前信号包括：

- 第三方风控服务结果
- 过短邮箱用户名
- 纯数字邮箱用户名
- 高风险国家
- 同 IP 短时间重复下单
- 历史欺诈记录
- 活跃服务信任减分
- 客户账号年龄信任减分
- 已付发票历史信任减分
- 邮箱验证信任减分

### 结账弹窗

结账弹窗内容可以直接在插件后台修改。

可编辑字段包括：

- 标题
- 引导说明
- 提示项
- 重点说明
- 按钮文本
- 校验提示

中文和英文文案可分别配置。

### 手动订单工具

插件后台可以通过 WHMCS 订单 ID 重新评分订单，也可以按当前规则处理，或通过 WHMCS `localAPI` 有意识地执行 `PendingOrder` / `FraudOrder`。

手动动作允许有意识地重新执行订单动作。自动 Hook 处理时，如果该订单已经用当前规则版本保存过决策，会跳过重复执行。

### 诊断和配置工具

插件后台包含：

- 最近 7 天和 30 天的订单风险决策指标。
- 数据库和 WHMCS 辅助函数诊断，用于排查升级或环境问题。
- 当前有效配置的只读 JSON 导出。
- JSON 配置导入，保存前会重新归一化。

## 运行 Hook

插件通过 `hooks.php` 注册以下 WHMCS Hook：

- `ClientAreaFooterOutput`：在结账页面注入安全确认弹窗。
- `ShoppingCartValidateCheckout`：在服务端校验结账确认状态。
- `DailyCronJob`：按保留策略清理旧审计日志和规则快照。
- `AdminAreaFooterOutput`：在 WHMCS 后台订单详情页注入风险面板。
- `AfterFraudCheck`：在 WHMCS 欺诈检查完成后执行订单风险评分。

## 数据表

插件启用时会创建以下数据表：

- `mod_peakrack_risk_settings`
- `mod_peakrack_risk_rule_versions`
- `mod_peakrack_risk_audit_logs`
- `mod_peakrack_risk_decisions`

停用插件时不会删除这些数据表，以便保留历史审计和风险处理记录。

升级时会执行增量结构检查并补齐缺失字段，包括用于重复执行保护的决策表 `rule_version` 字段。

## 项目结构

```text
peakrack_risk/
  peakrack_risk.php       插件入口、启用逻辑、后台页面和数据表创建
  hooks.php               WHMCS Hook 注册
  README.md               插件目录说明
  lib/
    AdminLang.php         后台界面语言文本
    Bootstrap.php         默认配置、设置读写、数据标准化和审计日志
    Checkout.php          结账弹窗生成
    RiskEngine.php        风险评分和订单处理辅助逻辑
```

## 使用建议

- 默认不启用自动欺诈标记。
- 新安装默认启用“仅记录日志”模式；升级时会保留已有后台配置。
- 生产环境首次部署建议先启用“仅记录日志”模式观察结果。
- 手动强制动作会调用 WHMCS `localAPI`，生产使用前建议先用非关键订单测试。
- 不要同时保留旧版独立 Hook 文件，否则同一逻辑可能重复执行。
- 每次修改弹窗内容或服务端确认设置后，都应完整测试一次结账流程。

## 升级说明

逐版本升级内容见 [UPGRADE.zh-CN.md](UPGRADE.zh-CN.md)。

## 开发检查

打包或发布前建议执行 PHP 语法检查：

```powershell
Get-ChildItem -Path peakrack_risk -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

预期结果：所有 PHP 文件均无语法错误。

## 许可协议

MIT License。完整条款见仓库根目录 [LICENSE](LICENSE)。
