# ANBK Cerdas

Platform try out ANBK berbasis Laravel 13 dan React/Inertia. Guru dapat mengelola kompetensi dan bank soal, membuat soal dengan bantuan AI, menyusun paket ujian fleksibel, serta melihat laporan hasil dan analisis butir.

AI hanya digunakan untuk pembuatan konten, review, chatbot belajar, dan ringkasan hasil. Alur ujian, autosave, timer, pemilihan soal, scoring, serta analisis kompetensi tetap berjalan deterministik tanpa menunggu provider AI.

## Daftar Isi

- [Fitur utama](#fitur-utama)
- [Arsitektur](#arsitektur)
- [Perilaku paket ujian](#perilaku-paket-ujian)
- [Menjalankan secara lokal](#menjalankan-secara-lokal)
- [Deploy production dengan Docker](#deploy-production-dengan-docker)
- [Konfigurasi environment](#konfigurasi-environment)
- [Operasional production](#operasional-production)
- [Backup dan restore](#backup-dan-restore)
- [Deploy tanpa Docker](#deploy-tanpa-docker)
- [Keamanan](#keamanan)
- [Load test](#load-test)
- [Validasi](#validasi)

## Fitur Utama

- Multi-sekolah berdasarkan NPSN.
- Login siswa menggunakan NPSN dan NISN tanpa password; akun dibuat otomatis pada akses pertama.
- Registrasi guru dengan persetujuan admin sekolah.
- CRUD kompetensi sekolah dengan kompetensi global read-only.
- Bank soal pilihan tunggal, pilihan kompleks, isian singkat, menjodohkan, dan tabel kategori.
- Stimulus opsional dan bundle satu cerita untuk 2–4 soal.
- Versioning soal, approval, duplikasi, arsip, audit log, serta snapshot soal yang sudah digunakan.
- Impor soal melalui CSV/XLSX.
- AI untuk variasi soal, soal cerita, review, chatbot privat siswa, dan ringkasan hasil.
- Paket manual, acak, komposisi per kompetensi, dan blueprint berdasarkan kompetensi, bentuk soal, serta kesulitan.
- Set soal personal per siswa untuk mode otomatis: soal dipilih saat siswa memulai ujian dan memprioritaskan soal yang paling jarang dikerjakan siswa tersebut.
- Timer, autosave server, cadangan jawaban di `localStorage`, sinkronisasi ulang, navigasi nomor, dan opsi wajib menjawab semua soal.
- Scoring deterministik, peta kompetensi, rekomendasi latihan, event integritas, laporan, dan ekspor CSV.
- Analisis tingkat kesukaran, daya pembeda kelompok atas–bawah 27%, efektivitas pengecoh, dan reliabilitas KR-20.

Analisis butir otomatis ditandai valid setelah jumlah respons mencapai `ITEM_ANALYSIS_MIN_RESPONSES`, dengan nilai default 30 peserta.

## Arsitektur

Stack production yang disediakan repository:

| Komponen | Implementasi |
|---|---|
| Backend | PHP 8.3+ / Laravel 13 |
| Frontend | React 18, TypeScript, Inertia, Vite, Tailwind CSS |
| Database | PostgreSQL 17 |
| Cache/session/queue | Redis 7 melalui Predis |
| Web/HTTPS | Apache di container aplikasi, Caddy sebagai reverse proxy |
| AI | Driver `fake` atau Gemini |
| File publik | S3-compatible storage seperti Cloudflare R2, atau disk `public` |
| Background process | Laravel queue worker dan scheduler |

File deployment utama:

- `compose.yaml`: app, queue, scheduler, PostgreSQL, Redis, dan Caddy.
- `Dockerfile`: multi-stage Composer, Node/Vite, dan PHP Apache.
- `.env.docker.example`: template environment production.
- `docker/Caddyfile`: HTTPS dan security headers.
- `scripts/backup-postgres.sh`: backup PostgreSQL format custom.

Endpoint health check tersedia di `/up`.

## Perilaku Paket Ujian

Mode pemilihan soal:

| Mode | Perilaku |
|---|---|
| Dipilih guru | Semua siswa menerima soal yang dipilih guru. Urutan dapat diacak. |
| Acak sederhana | Set soal dipilih ketika masing-masing siswa mulai. |
| Komposisi per kompetensi | Jumlah soal per kompetensi tetap, tetapi set soal dapat berbeda per siswa. |
| Blueprint | Kompetensi, bentuk, kesulitan, dan kuota tetap; set soal dapat berbeda per siswa. |

Untuk mode otomatis, setiap siswa memiliki set soal permanen di tabel `attempt_question`. Reload, logout, atau melanjutkan ujian tidak akan mengganti soal yang sudah diterima. Jika beberapa soal memiliki frekuensi pengerjaan sama, pemilihannya dilakukan secara acak.

## Menjalankan Secara Lokal

Prasyarat:

- PHP 8.3 atau lebih baru.
- Composer 2.
- Node.js 22 dan npm.
- Ekstensi PHP: `bcmath`, `curl`, `gd`, `intl`, `mbstring`, `pdo`, `sqlite3`, dan `zip`.

Instalasi:

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
composer dev
```

Buka `http://127.0.0.1:8000`.

`composer dev` menjalankan web server, queue worker, log viewer, dan Vite. Untuk pengembangan tanpa biaya AI gunakan:

```dotenv
AI_DRIVER=fake
```

Akun staff hasil seeder:

- Admin: `admin@example.com` / `password`
- Guru: `guru@example.com` / `password`

Login siswa dilakukan dari tab **Siswa** menggunakan NPSN 8 digit dan NISN 10 digit. Jika kombinasi belum ada, aplikasi meminta nama lalu membuat akun siswa secara otomatis. NPSN demo adalah `69999999`.

> Jangan menjalankan `php artisan migrate:fresh --seed` pada production karena seluruh data akan dihapus dan akun demo berpassword lemah akan dibuat ulang.

## Deploy Production dengan Docker

Docker Compose adalah jalur deployment yang direkomendasikan karena seluruh service dan ekstensi PHP sudah disiapkan.

### 1. Kebutuhan server

Minimum untuk pilot kecil:

- 2 vCPU.
- RAM 4 GB.
- SSD 30 GB.
- Ubuntu 22.04/24.04 atau distro Linux yang mendukung Docker.
- Docker Engine dengan plugin Docker Compose v2.
- Domain aktif yang mengarah ke IP server.
- Port TCP `80` dan `443`, serta UDP `443`, terbuka.

Untuk ujian serentak, kapasitas akhir harus ditentukan melalui load test. PostgreSQL dan Redis sebaiknya dipindahkan ke managed service atau server terpisah ketika beban meningkat.

### 2. Siapkan DNS dan firewall

Buat record `A`/`AAAA` domain, misalnya `anbk.sekolah.id`, menuju server. Caddy baru dapat menerbitkan sertifikat TLS setelah DNS benar dan port 80/443 dapat diakses publik.

Contoh UFW:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp
sudo ufw enable
```

PostgreSQL dan Redis tidak dipublikasikan oleh `compose.yaml`; jangan membuka port 5432 atau 6379 ke internet.

### 3. Ambil source code

```bash
sudo mkdir -p /opt/anbk-cerdas
sudo chown "$USER":"$USER" /opt/anbk-cerdas
git clone <URL_REPOSITORY> /opt/anbk-cerdas
cd /opt/anbk-cerdas
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

Jika source dikirim sebagai arsip, ekstrak ke `/opt/anbk-cerdas` dan pastikan `compose.yaml`, `Dockerfile`, serta folder `docker/` tersedia.

### 4. Buat APP_KEY

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

Salin seluruh hasil, termasuk awalan `base64:`, ke `APP_KEY` pada `.env.docker`. Jangan mengubah `APP_KEY` setelah aplikasi memiliki data karena session dan data terenkripsi lama tidak dapat dibaca.

### 5. Isi environment production

Minimal ubah nilai berikut di `.env.docker`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://anbk.sekolah.id
APP_DOMAIN=anbk.sekolah.id
APP_KEY=base64:HASIL_GENERATE

DB_PASSWORD=PASSWORD_DATABASE_PANJANG_DAN_ACAK
POSTGRES_PASSWORD=PASSWORD_DATABASE_PANJANG_DAN_ACAK

AI_DRIVER=gemini
GEMINI_API_KEY=API_KEY_ANDA
```

`DB_PASSWORD` dan `POSTGRES_PASSWORD` harus sama untuk stack Compose bawaan. Gunakan secret berbeda untuk setiap environment.

Jika AI belum ingin diaktifkan:

```dotenv
AI_DRIVER=fake
```

Driver `fake` membuat data simulasi dan tidak mengirim request ke Gemini.

### 6. Validasi konfigurasi dan build

```bash
docker compose --env-file .env.docker config --quiet
docker compose build --pull
```

Jika build berhasil, mulai semua service:

```bash
docker compose up -d
docker compose ps
```

Container `app` otomatis menjalankan `php artisan migrate --force` sebelum Apache dimulai. Container `queue` dan `scheduler` baru berjalan setelah health check aplikasi berhasil.

### 7. Optimasi Laravel

```bash
docker compose exec app php artisan optimize
docker compose exec app php artisan storage:link
docker compose exec app php artisan about
```

`storage:link` diperlukan jika memakai `AI_IMAGE_DISK=public`. Untuk container production, object storage lebih aman karena file pada filesystem container tidak dirancang sebagai penyimpanan permanen.

### 8. Buat admin pertama

Jangan menggunakan seeder demo pada production. Buat sekolah dan admin melalui Tinker:

```bash
docker compose exec app php artisan tinker
```

Jalankan di dalam Tinker dan ganti seluruh nilai contoh:

```php
$school = App\Models\School::firstOrCreate(
    ['npsn' => '12345678'],
    ['name' => 'Nama Sekolah']
);

App\Models\User::updateOrCreate(
    ['email' => 'admin@sekolah.id'],
    [
        'school_id' => $school->id,
        'name' => 'Admin Sekolah',
        'password' => bcrypt('PASSWORD_ADMIN_YANG_KUAT'),
        'role' => App\Enums\UserRole::Admin,
        'is_active' => true,
        'approved_at' => now(),
        'email_verified_at' => now(),
    ]
);
```

Ketik `exit` untuk keluar. Admin kemudian dapat menyetujui akun guru melalui menu **Pengguna**.

### 9. Smoke test

```bash
curl -fsS https://anbk.sekolah.id/up
docker compose exec app php artisan migrate:status
docker compose exec scheduler php artisan schedule:list
docker compose exec queue php artisan queue:failed
docker compose logs --tail=100 app queue scheduler caddy
```

Lanjutkan pemeriksaan browser:

1. Login admin.
2. Buat atau setujui satu guru.
3. Buat kompetensi dan satu soal draft.
4. Terbitkan soal dan buat paket ujian.
5. Login siswa dengan NPSN/NISN test.
6. Mulai ujian, simpan jawaban, submit, dan buka hasil.
7. Kirim satu chat AI jika Gemini aktif.

## Konfigurasi Environment

### Aplikasi

| Variable | Rekomendasi production |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | URL HTTPS tanpa slash di akhir |
| `APP_DOMAIN` | Host yang digunakan Caddy |
| `APP_TIMEZONE` | `Asia/Jakarta` |
| `LOG_CHANNEL` | `stderr` pada Docker |
| `LOG_LEVEL` | `info`, gunakan `warning` jika log terlalu ramai |

Jangan mengaktifkan `APP_DEBUG=true` pada server publik karena detail exception dan konfigurasi dapat terekspos.

### Database, Redis, session, dan queue

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=anbk
DB_USERNAME=anbk
DB_PASSWORD=secret

SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=180
```

`REDIS_QUEUE_RETRY_AFTER` harus lebih besar daripada timeout worker (`120` detik pada `compose.yaml`) untuk mencegah job yang masih berjalan diambil ulang.

### Gemini

```dotenv
AI_DRIVER=gemini
GEMINI_API_KEY=your-key
GEMINI_MODEL=gemini-3.1-flash-lite
GEMINI_IMAGE_MODEL=gemini-3.1-flash-lite-image
AI_DAILY_QUESTION_LIMIT=50
AI_DAILY_STORY_LIMIT=10
AI_DAILY_IMAGE_LIMIT=20
AI_DAILY_CHAT_LIMIT=20
AI_CHAT_CONTEXT_MESSAGES=12
```

Semua request AI dicatat di tabel `ai_generations`, termasuk model, status, token, estimasi biaya, dan error. Harga pada environment hanya dipakai untuk estimasi internal; sesuaikan ketika harga provider berubah.

Jalur ujian tidak memanggil Gemini. Gangguan provider AI tidak menghentikan timer, autosave, scoring, atau submit siswa.

### Object storage / Cloudflare R2

```dotenv
FILESYSTEM_DISK=s3
AI_IMAGE_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=anbk-assets
AWS_ENDPOINT=https://ACCOUNT_ID.r2.cloudflarestorage.com
AWS_URL=https://assets.anbk.sekolah.id
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Gunakan bucket khusus, kredensial dengan izin minimum, custom domain HTTPS, versioning, dan lifecycle policy. Jangan memasukkan secret object storage ke source control.

### Email

Template production masih memakai `MAIL_MAILER=log`. Dengan konfigurasi tersebut, email reset password hanya masuk ke log dan tidak dikirim ke pengguna. Konfigurasikan SMTP sebelum fitur lupa password dipakai secara operasional.

## Operasional Production

### Deploy pembaruan

Jalankan backup terlebih dahulu, lalu:

```bash
cd /opt/anbk-cerdas
./scripts/backup-postgres.sh /opt/backups/anbk
git pull --ff-only
docker compose build --pull
docker compose run --rm app php artisan migrate --force
docker compose up -d --remove-orphans
docker compose exec app php artisan optimize
docker compose ps
curl -fsS https://anbk.sekolah.id/up
```

`queue` dan `scheduler` ikut dibuat ulang menggunakan image terbaru. Jangan menjalankan `migrate:fresh`, `db:wipe`, atau `--seed` pada production.

### Queue

Pembuatan variasi soal, soal cerita, chatbot, ringkasan hasil, dan proses AI lain membutuhkan container `queue`.

```bash
docker compose logs -f queue
docker compose exec queue php artisan queue:failed
docker compose exec queue php artisan queue:retry all
```

Periksa akar error sebelum menjalankan `queue:retry all`, terutama untuk kegagalan API key, kuota Gemini, atau payload invalid.

### Scheduler

Container `scheduler` menjalankan Laravel scheduler. Saat ini scheduler memeriksa batch ilustrasi AI setiap lima menit.

```bash
docker compose exec scheduler php artisan schedule:list
docker compose logs -f scheduler
```

Jangan menjalankan scheduler kedua dari cron host jika container `scheduler` aktif.

### Log dan status service

```bash
docker compose ps
docker compose stats
docker compose logs --since=30m app
docker compose logs --since=30m queue
docker compose logs --since=30m postgres redis caddy
```

Pantau minimal:

- HTTP 5xx dan waktu respons.
- CPU, RAM, disk, dan inode.
- koneksi serta ukuran database.
- panjang queue dan jumlah failed jobs.
- error rate Gemini dan pemakaian token.
- kapasitas Redis.
- keberhasilan backup dan restore test.

### Maintenance mode

Untuk perubahan yang tidak backward-compatible:

```bash
docker compose exec app php artisan down --retry=60
# deploy dan migrasi
docker compose exec app php artisan up
```

Pastikan perintah `up` tetap dijalankan jika proses deploy gagal.

## Backup dan Restore

### Backup PostgreSQL

```bash
mkdir -p /opt/backups/anbk
./scripts/backup-postgres.sh /opt/backups/anbk
```

Script menghasilkan PostgreSQL custom dump dengan permission `600`. Kirim hasil backup ke storage di luar VPS dan gunakan retensi, misalnya harian 7 hari, mingguan 4 minggu, dan bulanan 6 bulan.

Contoh cron host pukul 02.15:

```cron
15 2 * * * cd /opt/anbk-cerdas && ./scripts/backup-postgres.sh /opt/backups/anbk >> /var/log/anbk-backup.log 2>&1
```

### Uji restore

Restore bersifat destruktif. Lakukan pada database staging atau saat maintenance:

```bash
docker compose exec -T postgres pg_restore \
  --clean --if-exists \
  -U anbk -d anbk < /opt/backups/anbk/anbk-YYYY-MM-DD-HHMMSS.dump
```

Setelah restore:

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize
```

Backup database tidak mencakup object storage. Aktifkan versioning/lifecycle pada R2 atau buat mekanisme backup terpisah.

### Rollback aplikasi

Rollback image/source tidak otomatis membatalkan migration. Prosedur aman:

1. Aktifkan maintenance mode.
2. Backup database saat ini.
3. Checkout tag/commit aplikasi sebelumnya.
4. Rebuild dan restart container.
5. Jika migration tidak backward-compatible, restore backup database yang sesuai.
6. Jalankan smoke test sebelum menonaktifkan maintenance mode.

## Deploy Tanpa Docker

Deployment native tetap memungkinkan dengan Nginx/Apache, PHP-FPM, PostgreSQL, Redis, Node.js hanya saat build, serta systemd/Supervisor.

### Dependensi PHP

Pasang PHP 8.3+ beserta ekstensi:

```text
bcmath curl gd intl mbstring opcache pcntl pdo_pgsql zip
```

Document root web server harus menunjuk ke `public/`, bukan root repository.

### Build release

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
```

Jangan menjalankan Vite development server pada production.

### Queue worker systemd

Contoh `/etc/systemd/system/anbk-queue.service`:

```ini
[Unit]
Description=ANBK Cerdas Queue Worker
After=network.target postgresql.service redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/anbk-cerdas
ExecStart=/usr/bin/php artisan queue:work redis --sleep=1 --tries=2 --timeout=120 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=130

[Install]
WantedBy=multi-user.target
```

Aktifkan:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now anbk-queue
```

Setelah setiap deploy jalankan `php artisan queue:restart`.

### Scheduler cron

```cron
* * * * * cd /var/www/anbk-cerdas && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Gunakan hanya satu scheduler aktif.

## Keamanan

Checklist minimum sebelum go-live:

- Gunakan HTTPS dan `APP_DEBUG=false`.
- Jangan commit `.env`, `.env.docker`, backup, API key, atau credential.
- Gunakan password kuat dan unik untuk database, admin, SMTP, object storage, dan provider AI.
- Batasi SSH menggunakan key, nonaktifkan password login, dan lakukan patch OS rutin.
- Jangan membuka PostgreSQL atau Redis ke internet.
- Simpan backup terenkripsi di lokasi berbeda dan uji restore berkala.
- Batasi akses bucket dan aktifkan versioning.
- Pantau audit log, failed jobs, dan aktivitas login.
- Jangan mengirim nama, NISN, NPSN, email, atau identitas siswa ke prompt AI.
- Gunakan akun dan data sintetis untuk staging/load test.

Login siswa NPSN+NISN dirancang untuk memudahkan siswa SD dan bukan autentikasi identitas tingkat tinggi. Siapa pun yang mengetahui kombinasi tersebut dapat mencoba masuk. Untuk penggunaan di luar lingkungan try out terkontrol, tambahkan PIN sesi, kode ujian, whitelist siswa, atau verifikasi guru.

Siswa yang memasukkan NPSN baru dapat menyebabkan sekolah placeholder dibuat otomatis. Admin perlu memantau dan membersihkan data sekolah yang tidak valid sebelum penggunaan skala besar.

## Load Test

Install k6 pada mesin penguji terpisah. Buat akun sintetis:

```bash
docker compose exec app php artisan anbk:load-users 69999999 --count=100 --grade=5 --force
```

Jalankan dari luar VPS:

```bash
k6 run \
  -e BASE_URL=https://anbk.sekolah.id \
  -e TARGET_VUS=100 \
  tests/load/anbk.js
```

Tambahkan `-e ASSESSMENT_ID=1` untuk menguji start/resume paket. Uji minimal login, daftar paket, start, pengambilan set soal personal, autosave, event integritas, submit, dan halaman hasil.

Jangan load test ke production saat ujian berlangsung.

## Validasi

Sebelum merge atau deploy:

```bash
composer test
npm run build
./vendor/bin/pint --test
```

Pemeriksaan production setelah deploy:

```bash
curl -fsS https://anbk.sekolah.id/up
docker compose exec app php artisan migrate:status
docker compose exec queue php artisan queue:failed
docker compose exec scheduler php artisan schedule:list
```

Deployment dianggap siap setelah migration selesai, health check sukses, queue dan scheduler aktif, login admin/siswa berhasil, autosave bekerja, serta backup telah diuji restore.
