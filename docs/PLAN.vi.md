# SEO Console — Kiến trúc & Lộ trình (bản nháp để duyệt)

> Trạng thái: **chờ duyệt**. Sau khi chốt, bản kỹ thuật chi tiết (tiếng Anh) sẽ nằm ở `docs/ARCHITECTURE.md` cho Agent review.

---

## 0. Những gì đã chốt

| Hạng mục | Quyết định |
|---|---|
| Phân phối | Dùng nội bộ agency + đăng miễn phí trên WordPress.org |
| Mô hình | Mỗi site cài 1 plugin độc lập, chạy hoàn toàn trên hosting của site đó |
| Claude API | Mỗi site tự nhập key riêng |
| Duyệt thay đổi | AI đề xuất → người xem, sửa lại nếu cần → bấm Duyệt. Không tự sửa ngầm |
| Ngôn ngữ | Giao diện tiếng Anh, có sẵn hệ thống dịch để thêm tiếng Việt sau |
| SEO plugin | Hỗ trợ cả Rank Math và Yoast (tự phát hiện) |
| Cache plugin | Tự phát hiện LiteSpeed Cache, WP Rocket, SiteGround Speed Optimizer (+ W3TC, WP Super Cache) |
| Ưu tiên số 1 | Tối ưu Page Speed |

## 1. Yêu cầu môi trường (tư vấn cho câu hỏi hosting)

Plugin sẽ hỗ trợ:

- **WordPress 6.5+**. Tính năng kết nối Claude Code qua MCP cần WordPress 6.9+; site cũ hơn vẫn chạy bình thường, chỉ ẩn tính năng đó.
- **PHP 7.4 → 8.4**. Nhiều shared hosting ở VN vẫn chạy 7.4 nên phải hỗ trợ. Plugin sẽ cảnh báo nếu PHP < 8.1, vì chỉ riêng việc nâng PHP đã giúp site nhanh hơn rõ rệt.
- **Apache, LiteSpeed, Nginx**: đều chạy được, vì plugin không phụ thuộc web server.
- **Thân thiện với shared hosting**: mọi tác vụ nặng chạy theo lô nhỏ trong nền, mỗi lô dưới ~20 giây, RAM thấp.

Bro không cần tự kiểm tra hosting. Plugin sẽ có trang **Environment** tự hiển thị: PHP, web server, RAM, OPcache, object cache, HTTP/2–3, gzip/brotli, WP-Cron có chạy thật không, plugin cache đang dùng. Mỗi mục có đánh giá Tốt / Cần sửa và lời giải thích dễ hiểu.

(Muốn xem ngay thì vào **Tools → Site Health → Info → Server**.)

## 2. Kiến trúc tổng thể

```
┌──────────────────────── WordPress site (hosting khách) ───────────────────────┐
│                                                                                │
│  SEO Console (plugin chính — bản WordPress.org)                                │
│  ├─ Core: Module manager · Settings · Security · Job queue · Change log/Undo    │
│  ├─ Modules: Speed · Audit/Crawl · Meta · Robots/Sitemap · Schema · AI         │
│  │           Bot Log · Content Inbox · GTM dataLayer                           │
│  ├─ Integrations: Rank Math / Yoast · LiteSpeed / WP Rocket / SG Optimizer     │
│  └─ Abilities (MCP) ── cho Claude Code đọc dữ liệu & đề xuất sửa               │
│                                                                                │
│  SEO Console Bridge (add-on riêng, KHÔNG lên WP.org, chỉ agency dùng)          │
│  └─ Deploy code từ GitHub (child theme + mu-plugin tối ưu) · Backup · Rollback │
└────────────────────────────────────────────────────────────────────────────────┘
        ▲ REST + Application Password            ▲ HTTPS API
        │                                        │
  Content Agent (Google Docs)        Claude API · Google PSI API · CrUX API
  Claude Code (local / cloud) ──► GitHub repo riêng cho từng site
```

**Vì sao tách Bridge?** WordPress.org cấm plugin tải và chạy code từ nguồn bên ngoài. Phần "Claude Code sửa code rồi đưa lên hosting" bắt buộc phải là add-on riêng do agency tự cài. Bản trên WP.org vì vậy sạch và dễ được duyệt.

**Kỹ thuật chống xung đột** (tóm tắt):
- Mọi thứ có tiền tố `seoc_` / namespace `SEOConsole\`.
- Thư viện bên ngoài được scope bằng Strauss.
- JS/CSS chỉ load trên trang admin của plugin.
- Frontend chỉ bị đụng tới khi module tương ứng được bật.
- Tác vụ nền dùng Action Scheduler.
- Giao diện admin dùng React + `@wordpress/components` (bộ UI chuẩn của WordPress), nên không đụng CSS của plugin khác.

## 3. Module — chi tiết

### 3.1 Speed (MVP — ưu tiên số 1)

**Đo lường**

| Nguồn | Dữ liệu | Tần suất |
|---|---|---|
| PageSpeed Insights API | Điểm Lab, LCP / CLS / TBT, danh sách lỗi (mobile + desktop) | Hằng ngày, cho ~5 URL đại diện: trang chủ, 1 bài, 1 page, 1 danh mục, 1 sản phẩm |
| CrUX API | Dữ liệu người dùng thật 28 ngày (LCP, INP, CLS, TTFB), cấp origin và cấp URL | Hằng ngày |
| CrUX History API | Xu hướng 6 tháng | Hằng tuần |

- **Dashboard:** biểu đồ xu hướng, trạng thái Đạt/Không đạt Core Web Vitals.
- **Bảng "Việc cần làm":** mỗi lỗi PSI được dịch thành hành động cụ thể, kèm mức độ ảnh hưởng.

**Chẩn đoán cấu hình cache plugin**

Plugin sẽ:
1. Đọc cấu hình hiện tại của LiteSpeed Cache / WP Rocket / SG Optimizer.
2. So với checklist tối ưu.
3. Chỉ ra cụ thể nên bật/tắt gì, kèm link tới đúng trang cài đặt.

Ở v1, plugin **chỉ đọc, không ghi** vào cấu hình của plugin khác. Đây là cách an toàn nhất, tránh xung đột.

**Tối ưu an toàn do plugin tự làm**

Mỗi mục là một công tắc riêng. Mỗi lần bật đều qua quy trình: **xem trước → duyệt → áp dụng → tự kiểm tra site → đo lại PSI để so sánh trước/sau → có Undo**.

1. **LCP image:**
   - Thêm `fetchpriority="high"` cho ảnh LCP mà PSI phát hiện.
   - Loại ảnh này khỏi lazy-load.
   - Preload ảnh đó trên đúng template.
2. **Preconnect / dns-prefetch** cho domain bên thứ ba lấy từ PSI (Google Fonts, GTM, CDN…).
3. **Dọn rác WordPress:**
   - Tắt emoji, oEmbed, RSD/wlwmanifest, XML-RPC.
   - Tắt dashicons với khách chưa đăng nhập.
   - Giảm tần suất Heartbeat.
   - Plugin tự bỏ qua mục nào mà cache plugin đã làm rồi.
4. **Database:**
   - Kiểm tra dung lượng autoload options (một nguyên nhân TTFB chậm rất phổ biến).
   - Dọn transient hết hạn.
   - Giới hạn số revision.
   - Dọn log của Action Scheduler.
5. **Ảnh:** liệt kê ảnh nặng, sai kích thước, chưa có WebP/AVIF (lấy từ PSI và thư viện Media), đề xuất cách xử lý. Nén ảnh thật sẽ làm ở giai đoạn sau.
6. **Kiểm tra server:** TTFB, OPcache, object cache, gzip/brotli, HTTP/2–3, PHP version.

**Những gì cần sửa code theme** (CSS chặn render, JS nặng của theme, HTML sai cấu trúc…) sẽ được đóng thành "task" để giao cho Claude Code (xem mục 4).

### 3.2 Audit / Crawl (MVP)

- **Cách crawl:**
  - Crawl nội bộ từ sitemap + link nội bộ.
  - Chạy nền theo lô, có giới hạn tốc độ để không làm chậm site.
  - Tự lên lịch hằng ngày; mặc định chạy ban đêm theo múi giờ của site.
- **Những gì được kiểm tra:**
  - Status code, redirect chain, link hỏng.
  - Canonical, noindex, hreflang.
  - Title/description: thiếu, trùng, quá dài/ngắn.
  - H1 thiếu hoặc nhiều H1, cấu trúc heading.
  - Ảnh thiếu alt.
  - Nội dung mỏng, trang mồ côi (không có link trỏ vào).
- **Báo cáo:**
  - Điểm sức khỏe tổng thể.
  - Danh sách lỗi nhóm theo mức độ.
  - So sánh với lần crawl trước (lỗi mới / lỗi đã hết).

### 3.3 Meta + Đề xuất bằng AI (MVP)

1. Claude đọc nội dung bài, từ khóa chính (lấy từ Rank Math/Yoast) và các thẻ hiện có.
2. Claude trả về đề xuất cho Title, Meta description, alt ảnh, Open Graph.
3. Bro xem dưới dạng **Hiện tại ↔ Đề xuất**, có ô để sửa trực tiếp, rồi bấm **Duyệt** (làm từng mục hoặc hàng loạt).
4. Khi duyệt, giá trị được ghi đúng vào trường của Rank Math hoặc Yoast. Plugin không tạo thêm trường riêng nên không bị trùng.
5. Mọi thay đổi vào **Change log**, có Undo.

**An toàn AI:**
- Claude chỉ được trả về JSON theo khuôn định sẵn. Plugin kiểm tra lại từng trường trước khi lưu.
- Nội dung crawl được coi là dữ liệu, không bao giờ được coi là lệnh. Cách này chống prompt injection.

### 3.4 Robots.txt & Sitemap (MVP)

- **Kiểm tra:**
  - Phân tích cú pháp robots.txt.
  - Xác định URL quan trọng đang bị chặn nhầm.
  - Kiểm tra sitemap có hợp lệ không.
  - Đối chiếu chéo với kết quả crawl: URL có trong sitemap nhưng bị noindex/redirect/404, hoặc URL quan trọng không có trong sitemap.
- **Sửa:** chỉ đề xuất. robots.txt có thể chỉnh trong plugin, nhưng phải xem trước, bấm duyệt, và có Undo. Nếu Rank Math/Yoast đang quản lý robots.txt thì plugin nhường cho chúng.

### 3.5 Schema (MVP)

- **Các loại schema:** tự sinh Article, BreadcrumbList, FAQ (phát hiện từ nội dung), Organization/LocalBusiness, Product (nếu có WooCommerce), HowTo, VideoObject.
- **Không xuất khối JSON-LD riêng.** Schema được **chèn vào graph của Rank Math/Yoast** qua filter của chúng, nên không bị trùng.
- **Quy trình:** Claude đề xuất schema → bro xem và duyệt → plugin tự kiểm tra lỗi cú pháp và thiếu trường bắt buộc trước khi lưu.

### 3.6 AI Chat (MVP)

- **Chat trong admin**, Claude đã nắm sẵn ngữ cảnh site: kết quả audit, PSI, plugin đang cài. Ví dụ hỏi: "Vì sao trang chủ LCP 4s?"
- **Chat chỉ được đề xuất.** Muốn áp dụng thì đề xuất phải đi qua hàng chờ duyệt như mọi thay đổi khác.
- **Hiển thị chi phí** token ước tính theo tháng, có thể đặt giới hạn chi.

### 3.7 Bot Log Dashboard (giai đoạn 2)

- **Cách ghi:** ghi lượt truy cập của bot ngay trong WordPress. Mỗi request chỉ tốn 1 câu INSERT nhẹ.
- **Phân loại bot:**
  - Search: Googlebot, Bingbot…
  - AI: GPTBot, ClaudeBot, PerplexityBot…
  - SEO tool: Ahrefs, Semrush…
  - Bot giả: xác minh Google/Bing bằng reverse DNS, chạy nền.
- **Dashboard:**
  - Lượt crawl theo ngày, theo loại bot.
  - Tỷ lệ status code.
  - Top URL bị crawl.
  - 404 mà Googlebot gặp.
  - URL bị crawl nhưng không có trong sitemap.
  - Xu hướng crawl của bot AI.
- **Lưu trữ:** dữ liệu chi tiết giữ 30 ngày, sau đó gộp theo ngày và giữ 12 tháng, để không phình database.
- ⚠️ **Hạn chế cần biết:** khi LiteSpeed Cache / WP Rocket / SG cache trả trang từ cache, request **không đi qua PHP**, nên plugin không ghi được lượt đó.
  - Hệ quả: số liệu là **mẫu một phần**. Vẫn đủ để thấy xu hướng, phát hiện 404 và lỗi crawl, nhưng không phải tổng chính xác.
  - Muốn có số đầy đủ thì sau này có thể thêm tính năng import access log từ hosting.
  - Dashboard sẽ ghi chú rõ hạn chế này.

### 3.8 Content Inbox — kết nối Agent quản lý nội dung (giai đoạn 2)

**Endpoint:** `POST /wp-json/seo-console/v1/content`
- Xác thực bằng **Application Password** của một user quyền Editor riêng cho Agent.
- **Dữ liệu Agent gửi lên:**
  - `external_id` (ID của Google Doc)
  - Tiêu đề, nội dung (HTML hoặc Markdown)
  - Danh mục, thẻ
  - Ảnh đại diện (URL)
  - SEO title/description, từ khóa chính
  - Thời gian đăng, trạng thái (nháp / lên lịch)
- **Plugin xử lý:**
  - Lọc HTML an toàn.
  - Tải ảnh về Media.
  - Ghi meta vào Rank Math/Yoast.
  - Lên lịch đăng.
- **Gửi lại cùng một `external_id`** thì plugin **cập nhật** bài cũ chứ không tạo bài trùng.
- **Lịch nội dung trong admin** cho thấy bài nào sắp đăng và bài nào đến từ Agent.
- **Tùy chọn kiểm tra trước khi đăng:** chạy audit SEO trên bài; nếu thiếu meta hoặc alt thì báo lại cho Agent hoặc giữ ở trạng thái chờ duyệt.

### 3.9 GTM dataLayer (giai đoạn 2)

- **Dữ liệu đẩy vào `dataLayer`:**
  - Loại trang, ID bài, danh mục, thẻ
  - Tác giả, ngày đăng, số chữ
  - Trạng thái đăng nhập, vai trò user
  - Với WooCommerce: dữ liệu sản phẩm/đơn hàng chuẩn GA4
- **Cấu hình:** chọn trường nào được bật.
- **Không chèn mã GTM** nếu site đã có mã đó, để tránh load 2 lần.

### 3.10 Giai đoạn sau

- Nén ảnh theo lô.
- Đề xuất sửa Semantic HTML.
- Asset manager (tắt CSS/JS của plugin trên trang không cần).
- Import access log.
- Bản dịch tiếng Việt.

## 4. Kết nối Claude Code (không cần SSH)

**Kênh 1 — MCP (dữ liệu & sửa trong phạm vi cho phép)**
- Plugin đăng ký các "ability" qua Abilities API của WordPress:
  - Đọc kết quả audit, PSI/CrUX, bot log
  - Chạy lại test
  - Tạo đề xuất sửa (vào hàng chờ duyệt)
- Claude Code (local hoặc cloud) kết nối qua MCP Adapter bằng Application Password.
- Claude Code **không** có quyền ghi file. Mọi đề xuất vẫn chờ bro duyệt.

**Kênh 2 — GitHub (sửa code)**
- Mỗi site có một repo GitHub riêng chứa:
  - Child theme
  - Mu-plugin `site-optimizations` (nơi chứa các tối ưu viết riêng cho site)
  - File `CLAUDE.md` mô tả site
- **Lần đầu:** Bridge xuất child theme hiện tại lên repo.
- **Quy trình mỗi lần sửa:**
  1. Bro giao việc. Claude Code đọc task từ plugin (qua MCP), sửa code trong repo và mở Pull Request.
  2. Bro (hoặc Agent review) xem PR rồi merge.
  3. Trong admin bấm **Deploy** (Bridge).
  4. Bridge backup bản cũ, kéo bản mới về, kiểm tra site còn sống không. Nếu lỗi thì **tự rollback**.

Như vậy Claude Code có toàn quyền trên code, nhưng mọi thay đổi đều có lịch sử Git, có review, và có nút quay lại.

## 5. Bảo mật — nguyên tắc cứng

**Quyền truy cập**
- Mọi chức năng kiểm tra quyền (`manage_options` hoặc capability riêng), có nonce, và REST `permission_callback`.
- Toàn bộ input được sanitize, output được escape.

**API key**
- Mã hóa khi lưu, không bao giờ gửi ra trình duyệt.
- Có thể khai báo trong `wp-config.php` thay vì lưu trong database.

**Thay đổi trên site**
- Plugin chính không có `eval` và không ghi file PHP.
- Mọi thay đổi do AI đề xuất là **dữ liệu theo khuôn định sẵn**, không phải code.

**An toàn vận hành**
- **Safe Mode:** đặt hằng số `SEOC_SAFE_MODE` trong `wp-config.php` thì toàn bộ can thiệp frontend bị tắt. Dùng khi site gặp sự cố.
- **Tôn trọng cấu hình khóa của hosting:** `DISALLOW_FILE_MODS`, `DISALLOW_FILE_EDIT`.
- **Dữ liệu gửi ra ngoài:** chỉ gửi sang Claude/Google khi bro đã bật module tương ứng. Tất cả được khai báo rõ trong readme đúng theo yêu cầu của WordPress.org.

## 6. Kiểm thử & tài liệu (vì không có team dev)

**Kiểm thử tự động**

| Lớp | Công cụ |
|---|---|
| Unit + Integration | PHPUnit với bộ test chính thức của WordPress |
| Phân tích tĩnh | PHPStan (WordPress stubs) |
| Coding standard + bảo mật | PHPCS với WordPress Coding Standards (bắt lỗi thiếu escape, thiếu nonce, SQL không prepare) |
| Chuẩn WP.org | Plugin Check (PCP), chính là công cụ đội duyệt WP.org dùng |
| E2E | Playwright chạy trên WordPress thật |
| CI (GitHub Actions) | Chạy tất cả ở trên theo ma trận: PHP 7.4 / 8.1 / 8.3, WordPress 6.5 / mới nhất, có/không Rank Math, có/không Yoast |

**Tài liệu cho Agent review**
- `ARCHITECTURE.md`: kiến trúc chi tiết.
- `SECURITY.md`: threat model và các điểm phải kiểm tra.
- `TESTING.md`: cách chạy test.
- `REVIEW_CHECKLIST.md`: checklist để Agent khác review từng PR.
- `readme.txt`: theo chuẩn WordPress.org.

## 7. Lộ trình

| Giai đoạn | Nội dung | Kết quả |
|---|---|---|
| **0. Nền móng** | Khung plugin, module manager, settings, mã hóa key, job queue, Change log + Undo, health check sau sửa, Safe Mode, CI + test | Plugin cài được, chưa có tính năng SEO |
| **1a. Speed** | PSI + CrUX + dashboard, chẩn đoán cache plugin, các tối ưu an toàn, trang Environment | **Bản dùng thử đầu tiên** |
| **1b. Audit + AI** | Crawl/audit, Meta, Robots/Sitemap, đề xuất AI + hàng chờ duyệt, AI Chat | MVP đầy đủ |
| **1c. Schema** | Schema tích hợp Rank Math/Yoast | MVP hoàn chỉnh → test trên site thật |
| **2** | Bot Log dashboard, Content Inbox, GTM dataLayer, MCP abilities | |
| **3** | Bridge (GitHub deploy), ảnh, semantic HTML, tiếng Việt, nộp WordPress.org | |

Mỗi giai đoạn đều kết thúc bằng: test xanh → bro cài lên site staging → phản hồi → sang giai đoạn tiếp.
