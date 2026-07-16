# Changelog

## 0.2.1

- 修复 Typecho 直接加载公开路由、后台面板或管理 Action 时依赖类未载入导致的 HTTP 500。
- 新增统一 `bootstrap.php`，所有独立入口均可自行加载协议与数据库组件。

## 0.2.0

- 新增 Typecho 后台“联邦宇宙”管理面板。
- 新增作者身份、关注者、投递队列和入站活动视图。
- 新增管理员队列处理、重试、删除、移除关注者和批量生成身份操作。
- 连续投递失败 8 次后保留任务，等待人工处理。
- 新增入站活动日志保留期，Cron 自动执行清理。
- 远端请求固定已验证的公网 DNS 结果，并在下载过程中限制响应大小。
- 扩展 Typecho 1.3.0 集成测试，覆盖后台注册、队列恢复和日志清理。

## 0.1.0

- 实现多作者 ActivityPub Actor、WebFinger、收件箱和发件箱。
- 实现文章 Create、Update、Delete 联邦投递。
- 实现 Follow、Accept、Undo Follow 和远端回复审核。
- 实现 HTTP Signatures、RSA 密钥、投递队列和 NodeInfo 2.1。
- 支持 MySQL、MariaDB、PostgreSQL 和 SQLite 建表。
