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

### 阈值

- **审核阈值**：订单风险分达到该值后转为 `Pending`。
- **欺诈阈值**：订单风险分达到该值后视为高风险。
- **API 重试次数**：调用 WHMCS `localAPI` 修改订单状态时的重试次数。
- **IP 爆发窗口**：检测同一 IP 重复下单的时间窗口。
- **IP 爆发订单数**：同一 IP 在窗口内达到该订单数后增加风险分。

### 名单配置

- 高风险国家
- 可信邮箱域名
- 白名单客户 ID
- 白名单客户组 ID
- 白名单邮箱域名
- 白名单 IP/CIDR

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

## 运行 Hook

插件通过 `hooks.php` 注册以下 WHMCS Hook：

- `ClientAreaFooterOutput`：在结账页面注入安全确认弹窗。
- `ShoppingCartValidateCheckout`：在服务端校验结账确认状态。
- `AfterFraudCheck`：在 WHMCS 欺诈检查完成后执行订单风险评分。

## 数据表

插件启用时会创建以下数据表：

- `mod_peakrack_risk_settings`
- `mod_peakrack_risk_rule_versions`
- `mod_peakrack_risk_audit_logs`
- `mod_peakrack_risk_decisions`

停用插件时不会删除这些数据表，以便保留历史审计和风险处理记录。

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
- 生产环境首次部署建议先启用“仅记录日志”模式观察结果。
- 不要同时保留旧版独立 Hook 文件，否则同一逻辑可能重复执行。
- 每次修改弹窗内容或服务端确认设置后，都应完整测试一次结账流程。

## 开发检查

打包或发布前建议执行 PHP 语法检查：

```powershell
Get-ChildItem -Path peakrack_risk -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

预期结果：所有 PHP 文件均无语法错误。

## 许可协议

本项目使用自定义源码可见许可协议。

允许下载、查看、修改，并用于你自己的 WHMCS 站点或内部业务场景。禁止出售、转售、重新打包分发、改名发布为竞争产品，或移除/替换原项目署名。

完整条款见 [LICENSE](LICENSE)。
