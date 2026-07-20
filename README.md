# Fediverse for Typecho

Fediverse 是面向 Typecho 1.3.0 的多作者 ActivityPub 插件。每位 Typecho 作者都可以拥有独立的联邦账号、RSA 密钥、收件箱、发件箱、关注者和正在关注集合，并通过文章、回复、点赞与转发参与联邦宇宙。

当前版本：`0.6.0`。完整版本记录见 [CHANGELOG.md](CHANGELOG.md)。

## 功能概览

### 作者身份

- 通过 WebFinger 发现 `@author@example.com`。
- 为每位作者提供独立的 ActivityPub `Person` Actor 和 2048 位 RSA 密钥。
- 支持昵称、简介、头像、Banner 和最多 4 组 Mastodon 兼容资料字段。
- 作者保存资料后向现有关注者发送签名的 `Update Person`，促使远端刷新缓存。
- 提供 `followers`、`following`、`inbox`、`outbox` 和共享收件箱。

### 发布与互动

- 将公开、无密码的 Typecho 文章作为 `Article` 发布。
- 支持文章 `Create`、`Update`、`Delete` 投递。
- 接收 `Follow`、`Like`、`Announce`、`Create Note` 及对应更新、删除和撤销活动。
- 可在后台查找并关注远端账号，处理 `Accept`、`Reject` 和取消关注。
- 提供按本地作者隔离的后台时间轴，可点赞、转发、回复及撤销互动。
- 将远端回复接入 Typecho 评论过滤器并写入待审核队列，审核通过后由主题原生评论列表显示。
- 提供主题可调用的文章互动区，显示点赞、转发、联邦回复数量和最近互动者。

### 运维与安全

- 验证 HTTP Signatures、Digest 和 Date，并对活动 ID 去重。
- 远端请求仅允许 HTTPS，校验公网 DNS 结果并限制响应大小。
- 投递队列支持指数退避、每批处理上限和最多 8 次重试。
- 提供按 Actor、来源域的入站速率限制和存储配额。
- 入站活动、时间轴和额度台账按保留期清理。
- 后台支持生成作者身份、同步作者资料、补发最近 20 篇文章、重试或删除队列任务。
- 支持 NodeInfo 2.1，以及 MySQL、MariaDB、PostgreSQL 和 SQLite。

## 运行要求

- Typecho 1.3.0。
- PHP 8.2 或更高版本。
- PHP OpenSSL 和 cURL 扩展。
- 可公开访问的 HTTPS 站点。
- URL 重写将 `/.well-known/*` 和 `/fediverse/*` 交给 Typecho。
- 能每分钟请求一次队列地址的 Cron 服务。

## 快速安装

1. 将完整的 `Fediverse` 目录放入 Typecho 的 `usr/plugins/`。
2. 在 Typecho 后台启用 **Fediverse**。
3. 打开插件设置，检查联邦域名、入站限制和队列参数，然后保存 Cron 令牌。
4. 每位作者在个人设置的 Fediverse 区域配置账号资料。
5. 配置 Web 服务器重写和 Cron。
6. 打开“管理 → 联邦管理”，确认作者身份、数据表和后台菜单已经就绪。

停用插件不会删除数据表或私钥，因此重新启用后联邦身份保持不变。

## 插件设置

### 全局设置

| 设置 | 默认值 | 说明 |
| --- | ---: | --- |
| 联邦功能 | 启用 | 总开关 |
| 联邦域名 | 留空 | 通常自动使用博客域名；只填写域名，不含协议或路径 |
| 默认账号简介 | Typecho 简介 | 作者未填写个人简介时使用 |
| 远端回复 | 接收并等待审核 | 可关闭远端回复写入 |
| 单 Actor 每 5 分钟活动上限 | 30 | 填写 `0` 停用 |
| 单来源域每 5 分钟活动上限 | 100 | 填写 `0` 停用 |
| 单 Actor 入站活动存储上限 | 2000 | 统计尚未过期的额度台账；填写 `0` 停用 |
| 单来源域入站活动存储上限 | 10000 | 统计尚未过期的额度台账；填写 `0` 停用 |
| 每次队列处理数量 | 20 | 单次后台处理或 Cron 处理的任务数 |
| 入站活动与时间轴保留天数 | 90 | 有效范围 7 至 3650 天 |
| Cron 令牌 | 随机生成 | 队列处理地址中的私密令牌 |

出现大量 `429` 时，应先确认是否存在异常来源，再按服务器容量调整入站限制；不建议直接关闭所有限制。

### 作者设置

每位作者可以独立设置：

- 是否启用自己的联邦账号。
- 联邦用户名；留空时使用 Typecho 登录名。
- 账号简介。
- 可公开访问的 HTTPS 头像地址。
- 可公开访问的 HTTPS Banner 地址。
- 最多 4 组资料字段名称与内容。

资料字段内容为 HTTPS 地址时会输出为安全的 `rel=me` 链接，其他内容按纯文本转义。联邦用户名只允许字母、数字、下划线和连字符；首次生成身份后不建议修改，否则 Actor URL 会改变。

## Web 服务器

必须确认以下地址由 Typecho 处理，而不是被 Web 服务器直接返回 404：

```text
https://example.com/.well-known/webfinger?resource=acct:author@example.com
https://example.com/.well-known/nodeinfo
https://example.com/fediverse/author/author
```

Nginx 的典型规则：

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
```

Apache 需要启用 `mod_rewrite`，并使用 Typecho 后台生成的伪静态规则。

如果 Typecho 安装在子目录，WebFinger 仍应位于域名根目录。需要在反向代理层将 `/.well-known/webfinger` 和 `/.well-known/nodeinfo` 转发到 Typecho 子目录中的对应路由。

## Cron 与投递队列

保存插件设置后，每分钟请求一次：

```text
https://example.com/fediverse/cron/你的Cron令牌
```

Linux Cron 示例：

```cron
* * * * * curl -fsS --max-time 50 'https://example.com/fediverse/cron/替换为令牌' >/dev/null
```

Cron 会处理当前批次的投递任务，并清理超过保留期的入站活动、时间轴和额度台账。任务失败后按指数退避重试；连续失败 8 次后停止自动重试并保留在后台。

Cron 令牌可能出现在服务器访问日志中，应限制日志访问，并尽量在反向代理层限制该路径的来源 IP。

## 升级

升级前先备份数据库和插件目录，然后完整覆盖文件，不要只上传发生变化的文件。

| 来源版本 | 升级操作 |
| --- | --- |
| `0.5.4` | 覆盖文件后打开一次原有“管理 → 联邦宇宙”；页面会升级数据表、改名为“联邦管理”并注册新的顶级“联邦宇宙”菜单。刷新后台后检查三个子页面 |
| `0.5.0` - `0.5.3` | 完成上述操作；如远端资料或文章曾漏投，可使用“同步作者资料”和“补发最近文章”，然后运行队列 |
| `0.2.0` - `0.4.x` | 完整覆盖后打开“管理 → 联邦管理”，让插件补建数据表和路由；确认 `bootstrap.php` 已上传 |
| `0.1.x` | 完整覆盖后停用并重新启用一次插件，以注册后台面板、Action 和全部路由 |

`0.6.0` 会新增入站额度台账，并将远端新回复和编辑后的回复恢复为待审核状态，同时接入 Typecho 原生评论过滤器。已有评论状态不会自动改变。

升级或更换主题不会自动修改主题模板，文章互动区和联邦回复标记仍需按“主题适配”重新接入。

## 后台入口

### 管理 → 联邦管理

用于运维和诊断：

- 查看作者联邦地址、Actor 状态和密钥生成时间。
- 查看投递队列和已停止重试的任务。
- 查看入站活动及处理状态。
- 立即处理队列、重试或删除任务。
- 批量生成作者身份、同步作者资料、补发最近 20 篇公开文章。
- 清理过期活动。

### 联邦宇宙

顶级菜单包含三个独立页面：

- **时间轴**：浏览已关注账号投递的新内容，并进行点赞、转发和回复。
- **关注者**：查看和移除正在关注本站作者的远端账号。
- **正在关注**：查找远端账号、发起关注、查看等待或拒绝状态并取消关注。

所有后台操作都要求管理员权限，并通过 Typecho 安全令牌校验。

## 关注与时间轴

在“联邦宇宙 → 正在关注”中选择本地作者身份，输入完整账号，例如 `@user@example.com`。插件通过 WebFinger 获取远端 Actor，将签名的 `Follow` 放入队列；远端返回 `Accept` 后状态变为“已关注”，返回 `Reject` 后显示“已拒绝”。

关注成功后，远端服务器会把公开的 `Note` 或 `Article` 主动投递到本站。插件验签并确认发送者处于关注列表后，将纯文本内容写入对应本地作者的后台时间轴。

时间轴支持：

- 点赞与取消点赞，对应 `Like` 和 `Undo Like`。
- 转发与取消转发，对应 `Announce` 和 `Undo Announce`。
- 发送包含 `inReplyTo` 的公开 `Create Note` 回复。
- 接收已有内容的 `Update` 和 `Delete`。

所有出站互动先进入投递队列。时间轴不会自动公开远端内容，当前也不下载或代理远端附件。

## 文章、回复与互动统计

文章发布时，插件只向当时已经关注该作者的远端收件箱投递。新关注者不会自动收到全部历史文章；需要时可在“管理 → 联邦管理”使用“补发最近文章”。

远端回复只有在以下条件全部满足时才会接收：

- `inReplyTo` 指向本站已发布文章。
- 文章允许评论。
- 收件人匹配文章作者。
- HTTP 签名有效且未超过入站限制。
- Typecho 评论过滤器没有拒绝该回复。

回复内容会转换为纯文本并以 `waiting` 状态写入评论表。文章互动区会统计已接收的待审核回复，但回复正文只有审核通过后才由主题评论列表公开。

Mastodon 的 Favourite 在 ActivityPub 中对应 `Like`，因此文章互动区统一显示为“点赞”，不再单独建立“收藏”统计。

## 主题适配

插件不会全局改写文章正文或评论 HTML。每个主题只需要在文章模板和评论回调中各增加一处调用。

### 显示文章互动区

在文章详情模板 `post.php` 中，将以下代码放在文章正文或标签列表之后、上一篇/下一篇导航或评论区之前。此处 `$this` 必须是当前文章的 `Widget_Archive`：

```php
<?php if (class_exists('Fediverse_Plugin')): ?>
    <?php Fediverse_Plugin::renderPostInteractions($this); ?>
<?php endif; ?>
```

渲染函数自带作用域限定的通用样式，继承主题颜色和字体，支持移动端、深色模式、键盘焦点、高对比度和减少动态效果。主题可通过 CSS 变量调整：

```css
.fediverse-interactions {
    --fediverse-divider: rgba(127, 127, 127, .28);
    --fediverse-muted-opacity: .62;
    --fediverse-focus: currentColor;
}
```

需要完全自定义 HTML 时，可只读取结构化数据：

```php
<?php
$interactions = class_exists('Fediverse_Plugin')
    ? Fediverse_Plugin::postInteractions((int)$this->cid)
    : array('likes' => 0, 'announces' => 0, 'replies' => 0, 'recent' => array());
?>
```

返回字段：

- `likes`：尚未撤销的远端点赞数量。
- `announces`：尚未撤销的远端转发数量。
- `replies`：已接收且尚未删除或撤销的联邦回复数量，包括待审核回复。
- `recent`：最多 12 条最近点赞或转发，包含 `type`、`actor` 和 `created`。

### 标记联邦回复

在主题 `comments.php` 的单条评论回调中，将以下代码放在评论作者名称之后：

```php
<?php if ((string)$comments->agent === 'ActivityPub'): ?>
    <span class="fediverse-comment-source">联邦宇宙</span>
<?php endif; ?>
```

调用 `renderPostInteractions()` 时已经包含 `.fediverse-comment-source` 的通用样式。若主题只调用 `postInteractions()` 自定义互动区，则需要自行定义该标记样式。

## 安全模型

- 所有入站活动必须带有效的 `Digest`、`Date` 和 HTTP Signature。
- Activity ID 用于去重，重复投递不会重复写入。
- 远端 URL 必须使用 HTTPS，且不能包含凭据。
- DNS 解析结果必须全部为公网地址；拒绝私有、保留和回环地址。
- 下载响应限制为 2 MiB，HTTPS 重定向最多 5 次，每一跳重新进行安全校验。
- 入站频率与存储配额按 Actor 和来源域分别统计，超限返回 HTTP `429`。
- 远端回复经过 Typecho `Widget_Feedback:comment` 过滤器，并默认进入待审核状态。
- 头像、Banner 和资料字段不接受原始 HTML；插件不会代理远端媒体。

## 常见问题

### 远端搜索不到本站账号

先直接检查 WebFinger 和 Actor 地址是否返回 `200`，并确认响应没有 BOM、空白或主题输出。若使用 CDN、反向代理或 `www` 跳转，联邦域名必须与 Actor ID 保持一致。

### 关注一直显示等待确认

检查“管理 → 联邦管理 → 投递队列”是否存在失败任务，再查看入站活动中是否收到 `Accept` 或 `Reject`。确认 Cron 正常运行，并避免裸域名与 `www` 分别生成不同 Actor ID。

### 新文章没有出现在远端

确认远端账号在文章发布前已经成为关注者，并检查投递队列。历史漏投可使用“补发最近文章”，随后立即处理队列或等待 Cron。

### 远端已有回复，但文章只显示数量

远端回复默认进入 Typecho 待审核队列。互动区会计入回复数量，但正文需要在 Typecho 评论后台审核通过后才显示。

### 头像、Banner 或资料字段没有更新

在个人设置中重新保存资料，或在“管理 → 联邦管理”执行“同步作者资料”，然后处理投递队列。远端实例仍可能保留自身缓存，需要等待其刷新。

### 收件箱返回 HTTP 429

表示 Actor 或来源域触发了速率限制或存储配额。先检查入站活动来源和 Cron 清理是否正常，再调整对应全局设置。

## 当前限制

- 暂不联邦发送 Typecho 本地评论。
- 时间轴暂不展开远端转发活动。
- 暂不处理媒体附件、投票、私信、通知和内容警告。
- 安装插件前的旧文章需要重新保存或使用“补发最近文章”才会主动投递。
- 不同 Fediverse 实现对 HTTP Signatures 的细节仍可能存在差异，生产部署应分别使用 Mastodon、Pleroma/Akkoma、GoToSocial 等实例验证。

## 上线检查

1. WebFinger 返回 `application/jrd+json`，Actor 返回 `application/activity+json`。
2. Mastodon 等远端可以搜索完整账号 `@author@example.com`。
3. 远端关注后，本站出现关注者并成功回送 `Accept`。
4. 发布测试文章后，队列成功发送且远端时间线出现文章。
5. 远端回复后，Typecho 后台出现待审核评论，审核通过后文章页显示正文。
6. 文章互动区正确显示点赞、转发和联邦回复数量。
7. “管理 → 联邦管理”没有持续失败或停止重试的任务。
8. Cron 能持续处理队列并清理过期数据。

## 卸载与数据

停用插件会保留联邦数据和作者私钥。需要永久卸载时，先备份数据库，再手工删除名称中包含 `fediverse_` 的插件数据表；删除私钥后无法恢复原有联邦身份。

## 许可证

GPL-2.0-or-later，与 Typecho 插件生态保持一致。
