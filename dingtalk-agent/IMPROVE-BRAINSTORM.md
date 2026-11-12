# AUDIT BRAINSTORM: dingtalk-agent (techrisk-dashboard)

Dokumen ini memuat analisa mendalam terhadap codebase `dingtalk-agent` dari 4 perspektif rekayasa perangkat lunak: SRE/Reliability, Product/UX, Security/Compliance, dan LLM/Agent Architecture. Analisa ini dibangun di atas 3 pass perbaikan sebelumnya (`858c885`, `dfb8eab`, `76c1ff3`) dan memfokuskan diri pada temuan berbasis bukti kode nyata.

---

## PERSPEKTIF A — SRE / Reliability Engineer

Perspektif ini menyoroti stabilitas jangka panjang bot ketika beroperasi berbulan-bulan tanpa henti: pertumbuhan memori, kebersihan disk, penanganan kegagalan dependensi, dan celah observabilitas.

### 1. [P0] Deadlock / Goroutine Hang pada `fireWatch` Karena Melepas Semaphore Tanpa Akuisisi
- **file:baris**: `internal/dingtalk/watches.go:136`
- **dampak**: Fungsi `fireWatch` dijalankan via goroutine di baris 127 (`go b.fireWatch(...)`), dan di baris 136 tertulis `defer func() { <-b.sem }()`. Namun, `fireWatch` **tidak pernah melakukan akuisisi** `b.sem <- struct{}{}`. Jika tidak ada turn percakapan lain yang sedang berjalan (`len(b.sem) == 0`), eksekusi `defer` akan mem-block selamanya di `<-b.sem` sehingga goroutine menggantung permanen (leak). Bila ada turn percakapan lain yang sedang jalan, token semaphore milik percakapan tersebut dicuri, mengacaukan limit konkurensi `MaxConcurrentAgents`.
- **usulan fix**: Tambahkan `b.sem <- struct{}{}` sebelum memproses turn di `fireWatch` (atau sebelum spawn goroutine di baris 127).
- **effort**: S

### 2. [P1] Child Goroutine Panic di `execTools` dan Async Tasks Membunuh Daemon
- **file:baris**: `internal/agent/loop.go:167`, `internal/dingtalk/stream.go:600`, `internal/dingtalk/stream.go:664`, `internal/tools/turnreport.go:46`
- **dampak**: `execTools` mengeksekusi panggilan tool paralel menggunakan `go func(idx int, call llm.ToolCall)` tanpa blok `defer recover()`. Hal yang sama terjadi pada goroutine background `learnProfile`, `summarizeNotionReads`, dan `ReportTurnAsync`. Di runtime Go, `recover()` pada goroutine induk (`runBatch` / `processPlain`) tidak menangkap panic di goroutine anak; panic apapun di dalam eksekusi tool atau decoding respon langsung membunuh (crash) seluruh proses bot.
- **usulan fix**: Pasang `defer func() { if r := recover(); r != nil { ... } }()` di setiap goroutine anak pada `execTools` dan async background tasks.
- **effort**: S

### 3. [P2] Pertumbuhan Memori dan Disk Unbounded pada `knowledge.jsonl` dan `s.knowledge`
- **file:baris**: `internal/memory/store.go:49-57`, `internal/memory/store.go:170`
- **dampak**: Setiap file dokumen yang diunggah dan setiap pembacaan Notion menambahkan `KnowledgeEntry` baru ke file `knowledge.jsonl` dan slice `s.knowledge` secara append-only. Berbeda dengan `kb.Index` yang memiliki batas 1000 chunk dan auto-eviction, `memory.Store` tidak memiliki batas atas, TTL, maupun kompaksi. Selama berbulan-bulan, pemakaian memori in-memory slice dan ukuran file di disk akan tumbuh tanpa batas.
- **usulan fix**: Batasi slice `s.knowledge` di memori (misal 200 entri terbaru) dan terapkan rotasi berkala atau pemangkasan entri kadaluarsa pada `knowledge.jsonl`.
- **effort**: M

### 4. [P2] Akumulasi File Biner Tanpa Cleanup di `data/exports/`
- **file:baris**: `internal/tools/reportpdf.go:86`, `internal/tools/deckpptx.go:50`, `internal/dingtalk/stream.go:794-817`
- **dampak**: Setiap pemanggilan `generate_pdf_report` atau `generate_deck` menghasilkan file `.pdf` atau `.pptx` baru di direktori `data/exports/`. Fungsi `deliverGenerated` mengunggah file tersebut ke DingTalk API namun tidak pernah menghapus atau merotasi file lokal tersebut. Setelah ribuan laporan dibuat, direktori exports akan memakan ruang disk yang signifikan tanpa mekanisme garbage collection.
- **usulan fix**: Tambahkan rutinitas pembersihan berkala (cron/ticker) yang menghapus file di `data/exports/` yang berusia lebih dari 7 hari.
- **effort**: S

### 5. [P2] Stampede Token Request DingTalk OpenAPI pada Pengiriman Proaktif
- **file:baris**: `internal/dingtalk/push.go:34,50,68`, `internal/dingtalk/reminders.go:116`
- **dampak**: Fungsi `SendMarkdownDM`, `SendFileDM`, `SendFileGroup`, dan `SendMarkdownGroup` membuat instance `newOpenAPIClient(cfg)` baru pada setiap panggilan alih-alih memanfaatkan client `b.api` yang sudah memiliki token caching. Akibatnya, setiap pengingat yang terpicu, alert watch yang menyala, dan pengiriman file selalu menembak endpoint `/v1.0/oauth2/accessToken`, memperlambat pengiriman dan berisiko terkena HTTP 429 rate limit.
- **usulan fix**: Refactor helper pengiriman proaktif agar menerima dan menggunakan client `openAPIClient` yang tersimpan di `b.api`.
- **effort**: S

### 6. [P2] Ketiadaan Graceful Draining pada Saat Shutdown (SIGTERM/SIGINT)
- **file:baris**: `cmd/agent/main.go:105-115`
- **dampak**: Saat sinyal SIGTERM atau Interrupt diterima, `main()` membatalkan context root dan hanya menunggu shutdown `internalSrv` (5 detik), lalu proses langsung keluar. Empat slot agen di `b.sem` dan worker di `inboxes` tidak di-drain secara anggun (graceful), sehingga request user yang sedang berjalan di tengah iterasi multi-tool terputus seketika tanpa jawaban dan riwayat turn tidak tersimpan.
- **usulan fix**: Tunggu pelepasan seluruh slot `b.sem` dengan timeout toleransi (misal 15 detik) sebelum menghentikan proses.
- **effort**: M

### 7. [P3] Hydration Riwayat Saat Boot Memuat Percakapan Kadaluarsa (Bypass TTL)
- **file:baris**: `internal/memory/store.go:209-215`, `internal/dingtalk/stream.go:112`
- **dampak**: `Store.loadHistory()` membaca seluruh isi `history.json` ke memori tanpa memfilter `LastSeen` terhadap `HistoryTTL`. Jika bot sempat offline selama beberapa hari lalu restart, riwayat lama yang sudah kadaluarsa kembali dimuat ke memori aktif `b.history` sampai ada pesan baru yang memicu fungsi pembersihan.
- **usulan fix**: Filter entri riwayat berdasarkan `HistoryTTL` di dalam `loadHistory()` sebelum mengembalikan map percakapan.
- **effort**: S

### 8. [P3] Observability Blind Spot: Ketiadaan Endpoint `/healthz` dan Metrik Runtime
- **file:baris**: `cmd/agent/main.go:83-98`, `internal/dingtalk/internalapi.go:51`
- **dampak**: Server HTTP internal hanya membuka rute `POST /internal/run`. Tidak ada probe liveness/readiness (`/healthz`) maupun metrik operasional (jumlah goroutine, antrean inbox, ketersediaan koneksi stream) sehingga monitoring eksternal (Kubernetes probe, Prometheus) tidak dapat mendeteksi saat bot hang diam-diam.
- **usulan fix**: Tambahkan handler `/healthz` sederhana yang memvalidasi koneksi stream SDK dan ketersediaan slot semaphore.
- **effort**: S

---

## PERSPEKTIF B — Product/UX utk user DingTalk (risk ops staff)

Perspektif ini mengevaluasi kenyamanan operasional staf risk ops: kejelasan status, penanganan error, retensi konteks dialog, dan interaksi dwibahasa (ID/EN).

### 1. [P1] Black Hole 15–45 Detik: AI Card Kosong Tanpa Status Selama Eksekusi Multi-Tool
- **file:baris**: `internal/dingtalk/aicard.go:153-159`, `internal/agent/loop.go:107-111`
- **dampak**: Ketika user meminta analisis komprehensif (misal: cari insiden, baca 2 post-mortem, buat ringkasan), bot langsung membuat AI Card dengan teks kosong (`msgContent: ""`). Selama 15–45 detik proses pengambilan data dari berbagai tool, tidak ada token streaming yang dihasilkan (karena deltas hanya mengalir pada ronde penyusunan jawaban akhir). Staf risk ops hanya melihat kartu kosong dengan spinner dan mengira bot macet atau error.
- **usulan fix**: Kirim pesan status progres secara berkala ke AI Card (`card.stream(ctx, "🔍 Sedang memeriksa data insiden...")`) saat tool mulai dijalankan.
- **effort**: M

### 2. [P1] Slot Riwayat Cepat Habis (History Starvation) Menghilangkan Konteks Follow-up
- **file:baris**: `internal/dingtalk/stream.go:999-1006`
- **dampak**: Pembatasan `historyKeepMsgs = 12` menghitung setiap pesan intermediate `tool` dan `assistant` (dengan tool_calls) sebagai satu unit pesan terpisah. Satu pertanyaan investigasi dengan 3–4 panggilan tool menghasilkan 6–8 pesan dalam sekali turn. Akibatnya, kapasitas 12 pesan habis hanya dalam 1 atau 2 giliran, sehingga saat user menanyakan pertanyaan follow-up ("bagaimana dengan insiden yang kedua tadi?"), konteks awal sudah hilang terpotong.
- **usulan fix**: Simpan riwayat percakapan dalam bentuk turn dialog user-assistant bersih (buang tool-call intermediate setelah turn selesai, atau hitung 12 turn dialog alih-alih 12 raw messages).
- **effort**: M

### 3. [P2] Penalti Latensi Debounce 800ms pada Setiap Pesan Tunggal
- **file:baris**: `internal/dingtalk/stream.go:41`, `internal/dingtalk/stream.go:258-278`
- **dampak**: Logika batching selalu menahan pesan pertama selama `batchQuietWait = 800ms` untuk mengantisipasi pesan susulan. Bahkan untuk staf yang hanya mengetik satu pertanyaan singkat, bot diam selama hampir satu detik sebelum mulai memproses, memperlambat perceived responsiveness (Time-to-First-Token).
- **usulan fix**: Buat debounce adaptif: jika antrean inbox kosong, mulai proses lebih awal atau perpendek jendela tunggu awal menjadi 250–300ms.
- **effort**: S

### 4. [P2] Inkonsistensi Bahasa: Seluruh Pesan Status dan Notifikasi Bot Hardcoded Bahasa Inggris
- **file:baris**: `internal/dingtalk/stream.go:380,498,500,547,720,729,751,802,809`, `internal/dingtalk/reminders.go:105`, `internal/dingtalk/watches.go:79`
- **dampak**: Staf risk ops di DANA Indonesia berinteraksi dominan dalam Bahasa Indonesia. Namun semua pesan ack ("👌 On it — working on that…"), notifikasi watch ("Watching... status changes will be reported here"), konfirmasi pengingat ("⏰ Saved reminder..."), dan notifikasi file ("sent to the group ✓") ditulis dalam bahasa Inggris statis, menghasilkan pengalaman bot yang kaku dan campur aduk.
- **usulan fix**: Gunakan template dwibahasa atau sesuaikan bahasa pesan bot dengan bahasa pertanyaan user / preferensi di `UserProfile`.
- **effort**: S

### 5. [P2] Pesan Error Opaque Tanpa Panduan Tindakan (Zero Actionable Feedback)
- **file:baris**: `internal/dingtalk/stream.go:324,547,720,729,751`
- **dampak**: Saat request gagal (misalnya kueri terlalu luas sehingga mencapai limit 60 iterasi, timeout backend dashboard, atau parameter tanggal invalid), bot hanya membalas pesan generik: "Sorry, that request failed. Please try again in a moment." User tidak mengetahui apakah masalah ada pada filter pencarian, backend dashboard, atau hak akses token.
- **usulan fix**: Terjemahkan error umum menjadi pesan edukatif untuk user (misal: "Analisis melebihi batas waktu pencarian, mohon persempit rentang tanggal insiden.").
- **effort**: S

### 6. [P3] Ketiadaan Menu Bantuan / Onboarding untuk Pengguna Baru
- **file:baris**: `internal/dingtalk/stream.go:162,204`
- **dampak**: Staf baru yang menyapa bot dengan kata "help", "halo", atau "menu" langsung diteruskan ke agent loop yang memakan waktu dan kuota token LLM, tanpa menampilkan navigasi kemampuan bot yang terstruktur.
- **usulan fix**: Tambahkan fast-path handler untuk kata kunci bantuan yang langsung mengirimkan kartu panduan fitur (Cari Insiden, Dokumen Notion, Buat PDF/Deck, Pengingat, Watch).
- **effort**: S

### 7. [P3] Pengabaian Gambar Diam-Diam Saat Fitur KB Dinonaktifkan
- **file:baris**: `internal/dingtalk/stream.go:189-198`
- **dampak**: Jika konfigurasi `AGENT_KB_TOOLS=0`, callback gambar diabaikan begitu saja tanpa ada balasan ke user (`if m.Msgtype == "picture" && b.know != nil`). User mengira bot rusak karena unggahan gambarnya tidak direspon sama sekali.
- **usulan fix**: Balas dengan pesan penjelasan ("Fitur analisa gambar dan knowledge base saat ini sedang dinonaktifkan.") jika gambar diterima saat `b.know == nil`.
- **effort**: S

---

## PERSPEKTIF C — Security / Compliance Engineer (fintech)

Perspektif ini berfokus pada standar keamanan fintech (DANA Indonesia / regulasi OJK & BI): pencegahan kebocoran data rahasia (PII/kredensial), SSRF guardrails, eksfiltrasi data, dan pembatasan penyalahgunaan (abuse prevention).

### 1. [P1] Celah SSRF pada Download Gambar (`fileURLAllowed` Tanpa Batasan Host)
- **file:baris**: `internal/dingtalk/file.go:171-175`, `internal/dingtalk/file.go:195-198`, `internal/dingtalk/file.go:119`
- **dampak**: `pictureDownloadURL` mengambil URL gambar langsung dari payload webhook (`pm.PictureDownloadURL` / `pm.DownloadURL`) dan hanya divalidasi oleh `fileURLAllowed` yang sekadar mengecek `u.Scheme == "https" && u.Host != ""`. Tidak ada allowlist domain dan tidak ada pemeriksaan IP privat. Klien `downloadHTTPClient` juga tidak menggunakan `guardedTransport()`. Penyerang yang dapat memalsukan atau menginjeksi payload callback bisa mengeksploitasi bot untuk menembak endpoint HTTPS internal atau link cloud metadata.
- **usulan fix**: Terapkan `guardedTransport()` pada `downloadHTTPClient` dan batasi host download hanya pada domain resmi DingTalk (`*.dingtalk.com`, `*.dingtalkapps.com`, `*.aliyun.com`).
- **effort**: S

### 2. [P2] Pesan Pengguna Berisi PII/Kredensial Dicatat Apa Adanya ke Log Stdout
- **file:baris**: `internal/dingtalk/stream.go:204`
- **dampak**: Baris `log.Printf("[dingtalk] message from %s (%s) in conversation %s: %s", ..., text)` mencatat isi pesan mentah user secara langsung ke standard log. Dalam operasional fintech, pertanyaan staf teknis sering memuat nomor rekening, nomor ponsel pelanggan, token debug, atau connection string, yang akhirnya bocor ke sistem log terpusat (ELK, CloudWatch, Datadog).
- **usulan fix**: Lakukan masking/redaksi (`guardrail.Redact`) atau pembatasan karakter sebelum teks pesan dimasukkan ke dalam log.
- **effort**: S

### 3. [P2] Penyimpanan History di Disk Tidak Meredaksi Kredensial dan PII (`data/history.json`)
- **file:baris**: `internal/dingtalk/stream.go:748`, `internal/memory/store.go:191-207`
- **dampak**: Di fungsi `finalize()`, pemanggilan `b.saveHistory(m.ConversationId, newHistory)` dijalankan **sebelum** `FilterReplyCap` meredaksi output asisten. Array `newHistory` menyimpan prompt mentah user dan balasan mentah asisten tanpa sensor ke file `history.json`. Jika ada kredensial atau nomor kartu yang lolos di giliran tersebut, data tersebut tersimpan permanen di disk dan kembali dikirimkan ke model LLM pada turn selanjutnya.
- **usulan fix**: Jalankan `guardrail.Redact` pada setiap pesan sebelum dimasukkan ke dalam `saveHistory()`.
- **effort**: S

### 4. [P2] Potensi DoS / Abuse Loop Pembuatan Pengingat Tanpa Kuota
- **file:baris**: `internal/dingtalk/reminders.go:99`, `internal/tools/schedules.go:64-88`
- **dampak**: Berbeda dengan modul `watch.Store` yang membatasi maksimal 20 watch per chat (`MaxPerConversation = 20`), fungsi penjadwalan reminder via `schedule_reminder` tidak memiliki pembatasan jumlah per user atau per percakapan. User nakal atau kueri loop dapat membuat ratusan job cron yang mengeksekusi LLM task setiap menit di dashboard dan bot.
- **usulan fix**: Tambahkan validasi kuota maksimum pengingat aktif per percakapan (misal maks 20) sebelum mengirim request POST ke dashboard.
- **effort**: S

### 5. [P2] Risiko Heap Exhaustion / OOM pada Ekstraksi Dokumen XLSX Besar
- **file:baris**: `internal/ingest/extract.go:65`
- **dampak**: `extractXlsx` menggunakan fungsi `f.GetRows(sheet)` dari excelize yang mengalokasikan seluruh baris dan sel sheet ke dalam slice memori heap `[][]string`. Berbeda dengan docx yang dilindungi `io.LimitReader(rc, 20<<20)`, file Excel berukuran kecil dengan struktur sparse atau jutaan baris kosong dapat menyebabkan memori membengkak drastis hingga proses terkena OOM killer.
- **usulan fix**: Gunakan iterator streaming `f.Rows(sheet)` dan hentikan pembacaan jika total baris melebihi 5.000 atau batas karakter teks sudah tercapai.
- **effort**: M

### 6. [P3] Ketiadaan Enkripsi Data-at-Rest untuk File Memory JSON/JSONL
- **file:baris**: `internal/memory/store.go:49,133,227`, `internal/watch/watch.go:61`
- **dampak**: Seluruh data riwayat (`history.json`), kontak staf (`users.jsonl`), ringkasan dokumen (`knowledge.jsonl`), dan preferensi profil (`profiles.json`) disimpan dalam bentuk teks biasa (plain text). Jika container host atau persistent volume diakses oleh pihak yang tidak berwenang, seluruh data historis insiden dan data staf dapat terbaca langsung.
- **usulan fix**: Wajibkan deployment menggunakan filesystem terenkripsi (misal encrypted volume k8s/AWS KMS) dan kunci izin akses file hanya pada `0o600`.
- **effort**: S

### 7. [P3] Akses Silang Data Dokumen dalam Group Chat Tanpa Validasi Hak Akses
- **file:baris**: `internal/dingtalk/stream.go:356-358,988-996`
- **dampak**: Dalam group chat (`ConversationType == "2"`), seluruh anggota grup berbagi riwayat percakapan yang sama. Dokumen atau data sensitif insiden yang diunggah oleh salah satu staf dapat diakses dan dianalisis oleh anggota grup lain yang mungkin tidak memiliki wewenang penanganan insiden tersebut.
- **usulan fix**: Tambahkan peringatan privasi saat user mengunggah dokumen di dalam group chat atau anjurkan pengunggahan dokumen sensitif melalui jalur Direct Message (DM).
- **effort**: S

---

## PERSPEKTIF D — LLM / Agent Architect

Perspektif ini membedah arsitektur agen otonom: efisiensi konteks, akurasi penalaran tool, sistem RAG, prompt caching, dan optimasi pemanggilan model.

### 1. [P0] Bug Fatal Eskalasi Heavy Model: `l.run` Tidak Pernah Memanggil Model Heavy
- **file:baris**: `internal/agent/loop.go:107-111`, `internal/agent/loop.go:141-155`
- **dampak**: Commit `76c1ff3` menambahkan fitur eskalasi model pintar (`runWithEscalation`) saat model dasar kehabisan budget iterasi. Namun di baris 149, fungsi tersebut hanya memanggil kembali `l.run(...)`. Di dalam `l.run`, pemanggilan LLM tetap mengarah ke `l.llmClient.ChatCompletion` / `ChatCompletionStream` yang secara baku terkunci ke model standar (`c.model`). Fungsi `ChatCompletionHeavy` di `client.go` **tidak pernah dipanggil sama sekali**. Akibatnya, eskalasi hanya mengulang kueri pada model yang sama dengan sisa budget lebih sedikit. Selain itu, mutasi `l.maxIterations = max(saved/2, 2)` merusak konkurensi antar request pada instance loop bersama.
- **usulan fix**: Berikan parameter model target ke `l.run` (atau boolean `heavy`), gunakan method completion yang sesuai, dan hapus mutasi state global `l.maxIterations`.
- **effort**: M

### 2. [P1] Rigid Tool Truncation 8KB Merusak JSON dan Memotong Laporan Post-Mortem
- **file:baris**: `internal/agent/loop.go:20,245-258`, `internal/tools/dashboard.go:200,345`
- **dampak**: Batas baku `MaxToolResultBytes = 8000` diterapkan pada semua tool selain `notion_read_page`. Pada tool `get_incident_markdown`, laporan post-mortem insiden yang berukuran 15–30KB dipotong di tengah jalan, menghilangkan bab akar masalah (root cause) dan timeline perbaikan. Pada tool `search_incidents`, pemotongan 8000 byte membelah string JSON secara acak (`...[truncated 12000 bytes]`), merusak struktur JSON dan memaksa LLM menebak data yang hilang.
- **usulan fix**: Daftarkan cap khusus untuk `get_incident_markdown` (misal 32KB–48KB) dan lakukan pemotongan hasil `search_incidents` pada batas elemen record JSON yang valid.
- **effort**: S

### 3. [P2] Over-fetching Payload pada `search_incidents` Membakar 80% Token Konteks
- **file:baris**: `internal/tools/dashboard.go:340-345`
- **dampak**: `handleSearchIncidents` me-marshal seluruh respons mentah dari Laravel API (termasuk relasi nested, timeline audit, dan puluhan atribut database yang tidak relevan) ke dalam prompt LLM. Untuk 20 insiden, ukuran payload mencapai >25KB dan langsung terpotong oleh limit 8KB. Padahal untuk pencarian awal, LLM hanya membutuhkan ringkasan identitas insiden.
- **usulan fix**: Lakukan proyeksi kolom (hanya kirimkan: `no`, `title`, `severity`, `status`, `date`, `fund_loss`) sebelum mengembalikan string JSON ke LLM.
- **effort**: S

### 4. [P2] RAG Search Tanpa Similarity Threshold Menghasilkan Halusinasi
- **file:baris**: `internal/kb/kb.go:245-261`, `internal/tools/knowledge.go:60-71`
- **dampak**: Fungsi `Index.Search` mengurutkan chunk berdasarkan cosine similarity dan tanpa syarat mengambil `searchTopK = 6` potongan teratas. Tidak ada batas ambang kemiripan minimum (misal `similarity > 0.45`). Jika user menanyakan prosedur yang belum pernah diunggah, fungsi tetap menyodorkan 6 chunk yang sama sekali tidak berhubungan, dan LLM mencoba menjawab halusinasi berdasarkan cuplikan acak tersebut.
- **usulan fix**: Terapkan threshold cosine similarity minimum (misal 0.45); jika tidak ada chunk yang lolos threshold, kembalikan pesan bahwa dokumen tidak ditemukan.
- **effort**: S

### 5. [P2] Chunking RAG Berbasis Rune Mentah Merusak Struktur Paragraf dan Tabel
- **file:baris**: `internal/kb/kb.go:365-385`
- **dampak**: Fungsi `chunkText` memecah teks dokumen semata-mata berdasarkan panjang array rune (`runes[i:end]`) dengan langkah `chunkSize - chunkOverlap`. Pemecahan ini tidak memperhatikan batas baris (`\n`), heading markdown (`#`), maupun batas tab sel tabel dari ekstraktor Excel/Word. Sel-sel tabel insiden terpotong di tengah jalan sehingga makna baris tabel rusak saat diindeks.
- **usulan fix**: Implementasikan recursive semantic chunking yang memotong teks pada pemisah paragraf (`\n\n`), baris (`\n`), atau baris tabel sebelum memotong pada batas karakter.
- **effort**: M

### 6. [P3] Pruning Konteks `PruneContextBudget` Menyerah Saat Tool Result Habis
- **file:baris**: `internal/agent/loop.go:261-285`
- **dampak**: Logika `PruneContextBudget` hanya mencari pesan ber-role `"tool"` untuk diganti menjadi `"[tool result omitted]"`. Jika seluruh pesan tool sudah dipangkas namun akumulasi dialog (teks input user dan balasan asisten terdahulu) masih melebihi `MaxContextChars (100k)`, loop berhenti (`if !found { break }`). Request tetap dikirim dengan ukuran raksasa melebihi context limit API gateway.
- **usulan fix**: Tambahkan langkah pemangkasan giliran dialog user-assistant paling lama jika pemangkasan tool results belum mencukupi batas karakter.
- **effort**: S

### 7. [P3] Stempel Jam Menit pada System Prompt Merusak Efisiensi Prompt Caching
- **file:baris**: `internal/agent/loop.go:75`, `internal/agent/loop.go:240`
- **dampak**: Di akhir `buildSystemPrompt`, string jam server (`Current date/time: Mon 2006-01-02 15:04 WIB`) ditempelkan langsung pada pesan sistem. Karena string pesan sistem berubah setiap menit, fitur provider prompt caching (KV cache reuse pada OpenAI/DeepSeek gateway) selalu miss, meningkatkan biaya token dan waktu latensi generasi.
- **usulan fix**: Pertahankan pesan sistem tetap statis (hanya aturan domain dan tools) dan tempatkan konteks jam/profil dinamis ke dalam pesan sistem terpisah atau di akhir pesan user.
- **effort**: S

### 8. [P3] Pemanggilan Dashboard di `handleSearchIncidents` Mengabaikan Context Cancellation
- **file:baris**: `internal/tools/dashboard.go:335`
- **dampak**: `handleSearchIncidents` menerima parameter `ctx context.Context`, namun di baris 335 memanggil `c.get(context.Background(), "/incidents?"+q.Encode())`. Jika user membatalkan pesan atau turn terkena timeout context, request HTTP ke backend Laravel tetap berjalan di latar belakang dan membuang resource server.
- **usulan fix**: Teruskan `ctx` yang diterima fungsi ke pemanggilan `c.get(ctx, ...)`.
- **effort**: S

---

## TOP 10 PRIORITAS

Tabel gabungan rekomendasi perbaikan dari seluruh perspektif, diurutkan berdasarkan dampak terhadap sistem dan rasio hasil per usaha (Impact/Effort):

| No | Temuan / Masalah | Perspektif | Severity | File:Baris | Dampak Utama | Usulan Solusi Ringkas | Effort |
|:--:|:---|:---:|:---:|:---|:---|:---|:---:|
| 1 | **Bug Eskalasi Heavy Model** | D | **P0** | `internal/agent/loop.go:141-155` | Model heavy tidak pernah terpakai; retry memakai model default & race condition pada `maxIterations` | Lewatkan parameter model heavy ke `l.run` dan hapus mutasi state global | **M** |
| 2 | **Deadlock Semaphore di `fireWatch`** | A | **P0** | `internal/dingtalk/watches.go:136` | Goroutine watch macet selamanya jika sem kosong; mencuri kuota konkurensi turn lain | Tambahkan `b.sem <- struct{}{}` sebelum proses turn atau sebelum goroutine spawn | **S** |
| 3 | **Child Goroutine Panic di `execTools`** | A | **P1** | `internal/agent/loop.go:167` | Panic pada eksekusi tool paralel langsung mematikan (crash) seluruh daemon bot | Pasang `defer recover()` di dalam setiap goroutine anak worker tool | **S** |
| 4 | **Celah SSRF pada Download Gambar** | C | **P1** | `internal/dingtalk/file.go:171,195` | Validasi hanya cek `https://`; rentan eksfiltrasi data via internal HTTPS / cloud metadata | Terapkan `guardedTransport()` dan whitelist domain DingTalk/Aliyun | **S** |
| 5 | **Black Hole AI Card 15–45 Detik** | B | **P1** | `internal/dingtalk/aicard.go:153` | User melihat kartu kosong tanpa kejelasan saat multi-tool sedang memproses | Kirim status update progres berkala ke AI card saat tool dimulai | **M** |
| 6 | **History Starvation Memotong Konteks** | B | **P1** | `internal/dingtalk/stream.go:999` | 12 raw messages cepat habis oleh tool messages; konteks dialog follow-up hilang | Simpan riwayat percakapan bersih user-assistant (buang intermediate tool) | **M** |
| 7 | **Tool Truncation 8KB Merusak JSON** | D | **P1** | `internal/agent/loop.go:20,245` | Post-mortem terpotong & string JSON insiden rusak, memicu halusinasi | Naikkan cap post-mortem ke 32KB/48KB & potong data pada batas elemen record | **S** |
| 8 | **Over-fetching Payload `search_incidents`** | D | **P2** | `internal/tools/dashboard.go:340` | Respons mentah Laravel memboroskan 80% token prompt dan cepat terpotong 8KB | Proyeksikan hanya kolom esensial sebelum dikembalikan ke LLM | **S** |
| 9 | **RAG Search Tanpa Similarity Threshold** | D | **P2** | `internal/kb/kb.go:245` | Query tanpa dokumen yang cocok tetap menyodorkan 6 chunk acak | Pasang ambang batas minimum cosine similarity (misal 0.45) | **S** |
| 10 | **Pesan User Verbatim Tercatat di Log Stdout** | C | **P2** | `internal/dingtalk/stream.go:204` | PII dan kredensial sensitif dalam prompt user bocor ke container log | Masking atau redaksi teks pesan user sebelum logging | **S** |
