# Changelog

## 0.4.3

- 构建文章 ActivityPub 对象时隔离并丢弃主题或内容插件的意外输出，修复 Outbox 和单篇对象被 UTF-8 BOM 污染后 Mastodon 无法解析文章的问题。

## 0.4.2

- 联邦 Actor、活动、对象和签名 keyId 固定使用插件配置的规范联邦域名，避免通过 `www` 或其他 Host 运行 Cron 时产生第二套身份。
- 修复 Pleroma 宽松接受动态 Host Actor、但 Mastodon 因 Actor 与 WebFinger 规范 ID 不一致而持续等待确认的问题。

## 0.4.1

- WebFinger 与 Actor JSON 请求支持最多 5 次安全 HTTPS 重定向，每一跳重新校验 DNS 和公网 IP，兼容使用规范域名跳转的 GoToSocial 实例。
- WebFinger 使用专用 JRD `Accept` 请求头，兼容拒绝 ActivityPub 媒体类型的部分 Pleroma/Akkoma 实例。
- 远端账号解析错误会区分 WebFinger 和 Actor 阶段，并显示最终请求地址，便于定位实例端的 404。

## 0.4.0

- 新增远端账号 WebFinger 查找、主动关注、取消关注、`following` 集合以及 `Accept`、`Reject` 状态处理。
- 新增按本地作者隔离的后台时间轴，接收已关注账号的 `Create`、`Update` 和 `Delete`。
- 新增时间轴点赞、转发、回复及对应 `Undo`，所有出站互动复用签名投递队列。
- 新增可被远端解引用的本地回复对象路由，以及 MySQL、PostgreSQL、SQLite 数据表。
- 新增后台“正在关注”和“时间轴”视图，并完善空状态、确认、加载和移动端交互。
- 远端域名同时提供 IPv4 与 IPv6 时优先连接公网 IPv4，避免服务器没有 IPv6 出口时关注请求超时。

## 0.3.0

- 修复常见字符串形式 `attributedTo` 导致远端回复处理失败的问题。
- 支持远端回复 `Update`，编辑后的评论会重新进入审核队列；`Delete` 与 `Undo Create` 会删除对应评论。
- 改进 `Like`、`Announce` 与 `Undo` 的目标解析和活动状态记录，并限制本地对象匹配到本站 URL。
- 改进后台活动名称、处理状态、复制反馈、危险操作确认和操作后的视图回跳。
- 新增文章互动数据与渲染函数，Berry 文章详情页可显示联邦点赞、转发、回复和最近互动者。

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
