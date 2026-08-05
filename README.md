# ANBK Cerdas

Platform try out ANBK berbasis Laravel 13, React/Inertia, PostgreSQL, Redis, dan AI asynchronous. AI membantu guru membuat draft variasi soal dan menulis ringkasan hasil; pengerjaan, scoring, analisis kompetensi, dan rekomendasi tetap berjalan deterministik tanpa ketergantungan AI.

## Fitur MVP

- Bank soal multi-sekolah dengan kompetensi, tingkat kesulitan, status review, dan lineage variasi.
- Soal terbit bersifat immutable: edit membuat versi draft baru, sementara paket menyimpan snapshot soal dan kunci jawaban.
- Pembuatan tiga variasi soal melalui queue dengan kuota harian dan audit token/biaya.
- Pembuatan satu cerita dan 2–4 soal draft hanya dari tema yang dimasukkan guru.
- Paket try out berdasarkan jenjang dengan soal yang sudah disetujui guru.
- Paket ujian fleksibel: jenis reguler/custom, durasi, jumlah soal, pilihan manual/otomatis, jadwal, pengacakan, navigasi, dan wajib jawab.
- Blueprint paket terstruktur berdasarkan kuota kompetensi, bentuk soal, dan tingkat kesulitan.
- Paket dapat diedit dan diterbitkan ulang selama belum ada peserta yang memulai pengerjaan.
- Timer, autosave server, cadangan jawaban di `localStorage`, dan sinkronisasi ulang saat online.
- Scoring pilihan tunggal, pilihan kompleks, isian singkat, menjodohkan, serta tabel pilihan kategori dengan autosave.
- Peta capaian per kompetensi dan 2–3 rekomendasi dari bank soal.
- Ringkasan AI tanpa mengirim nama atau identitas siswa.
- Room privat siswa–AI untuk pendampingan belajar, ringkasan otomatis pascates, kuota harian, guard ujian aktif, dan pengawasan read-only guru.
- Driver AI `fake` untuk pengembangan dan Gemini untuk produksi.
- Registrasi guru dengan persetujuan admin sekolah, manajemen status akun, dan audit aktivitas penting.
- Edit, duplikasi, arsip, serta impor soal dari CSV/XLSX.
- Laporan nilai, kompetensi, analisis butir lanjutan, event integritas, dan ekspor CSV/cetak PDF.

Analisis butir menghitung tingkat kesukaran, daya pembeda kelompok atas–bawah 27%, efektivitas pengecoh, serta reliabilitas KR-20. Penandaan otomatis baru aktif setelah minimal 30 peserta menyelesaikan paket agar hasil tidak menyesatkan. Ambang tersebut dapat diubah melalui `ITEM_ANALYSIS_MIN_RESPONSES`.

## Menjalankan Lokal

Prasyarat: PHP 8.3+, Composer 2, dan Node.js 22+.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
composer dev
```

Buka `http://127.0.0.1:8000`.

Akun demo:

- Guru: `guru@example.com` / `password`
- Murid: `murid@example.com` / `password`
- Admin: `admin@example.com` / `password`

`composer dev` menjalankan web server, queue worker, log viewer, dan Vite secara bersamaan. Konfigurasi lokal memakai queue `sync` agar SQLite tidak mengalami lock; deployment Docker tetap memakai queue Redis.

Guru dapat memilih **Guru** pada halaman pendaftaran dan memasukkan NPSN sekolah 8 digit. Akun dibuat dalam status menunggu serta belum dapat login. Admin sekolah membuka menu **Pengguna**, memfilter **Menunggu persetujuan**, lalu menekan **Setujui Guru**. Pendaftaran murid tetap langsung aktif. NPSN sekolah demo adalah `69999999`.

## AI

Pengembangan lokal menggunakan:

```dotenv
AI_DRIVER=fake
```

Produksi menggunakan:

```dotenv
AI_DRIVER=gemini
GEMINI_API_KEY=your-key
GEMINI_MODEL=gemini-3.1-flash-lite
GEMINI_IMAGE_MODEL=gemini-3.1-flash-lite-image
AI_IMAGE_DISK=public
```

Semua panggilan disimpan di tabel `ai_generations`, termasuk provider, model, status, token input/output, estimasi biaya dalam micro-USD, dan error. Ubah harga token pada environment saat harga provider berubah.

Alur soal cerita: buka **Bank Soal → Soal Cerita AI**, masukkan tema, pilih 1–5 paragraf dan 2–4 soal, lalu tunggu AI menyelesaikan paket. AI memilih kompetensi yang tersedia dan menyimpan semua soal sebagai draft; guru tetap perlu memeriksa, mengedit, memvalidasi, dan menerbitkannya.

Setelah paket cerita selesai, guru dapat membuat satu ilustrasi 16:9 yang dipakai bersama oleh seluruh soal. Ilustrasi menggunakan Gemini Batch API dengan model `gemini-3.1-flash-lite-image`; estimasi konfigurasi saat ini US$0,0168 atau sekitar Rp300 per gambar 1K. Batch dipantau saat halaman dibuka dan oleh scheduler setiap lima menit. Fitur gambar Gemini memerlukan billing aktif; harga dapat berubah sehingga nilai `GEMINI_IMAGE_BATCH_COST_MICROUSD` perlu disesuaikan.

Untuk penyimpanan lokal, jalankan `php artisan storage:link`. Deployment Docker menggunakan `AI_IMAGE_DISK=s3` agar ilustrasi disimpan ke object storage/R2.

## Deployment Docker

```bash
cp .env.docker.example .env.docker
php artisan key:generate --show
```

Masukkan hasil perintah terakhir ke `APP_KEY` dalam `.env.docker`, isi domain, password PostgreSQL, kredensial R2, serta API key Gemini. Kemudian jalankan:

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f app queue
```

Stack Docker berisi Caddy, Apache/PHP, queue worker, scheduler, PostgreSQL 17, dan Redis 7. Caddy mengurus HTTPS otomatis saat DNS domain sudah mengarah ke server.

## Backup

Backup PostgreSQL perlu dijalankan dari cron host dan dikirim ke lokasi berbeda dari VPS:

```bash
./scripts/backup-postgres.sh /lokasi/backup
```

Uji restore secara berkala:

```bash
docker compose exec -T postgres pg_restore --clean --if-exists -U anbk -d anbk < backup.dump
```

Object storage sebaiknya memakai versioning dan lifecycle terpisah. Jangan menyimpan nama, email, atau identitas siswa dalam payload AI.

## Load Test

Pasang k6 pada mesin penguji, buat akun khusus, lalu jalankan skenario dari luar VPS aplikasi:

```bash
php artisan anbk:load-users 69999999 --count=100 --grade=5
k6 run -e BASE_URL=http://127.0.0.1:8000 -e TARGET_VUS=100 tests/load/anbk.js
```

Tambahkan `-e ASSESSMENT_ID=1` untuk menguji start/resume paket. Jangan memakai akun murid nyata untuk load test. Perintah pembuatan akun membutuhkan `--force` jika dijalankan pada environment production.

## Validasi

```bash
composer test
npm run build
./vendor/bin/pint --test
```

Sebelum ujian serentak, lakukan load test terhadap login, daftar paket, pengambilan soal, autosave jawaban, dan submit. Jalur ujian tidak memanggil provider AI sehingga dapat diskalakan terpisah dari worker AI.
