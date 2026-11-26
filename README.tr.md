<div align="center">

[English](README.md) · **Türkçe**

# FluxWP Docker

**Coolify** ve düz **Docker Compose** için canlıya hazır WordPress yığınları: ayarlı PHP ve OPcache, gerçek yükleme limitleri, healthcheck'ler, sabit imaj sürümleri, worker'da WP-Cron, isteğe bağlı Redis nesne önbelleği ve tek ayarla FluxWP Panel kurulumu.

![WordPress 7.1](https://img.shields.io/badge/WordPress-7.1-21759b?logo=wordpress&logoColor=white) ![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4?logo=php&logoColor=white) ![Coolify](https://img.shields.io/badge/Coolify-hazır-6b16ed) ![Docker Compose](https://img.shields.io/badge/Docker%20Compose-v2-2496ed?logo=docker&logoColor=white)

</div>

## Yığını seç

| Yığın | Web sunucusu | Veritabanı | Nesne önbelleği | Ne için |
|---|---|---|---|---|
| `mariadb` | Apache + mod_php | MariaDB 11.8 LTS | yok | Küçük siteler, Coolify'ın hazır şablonuna en yakın olan |
| `mysql` | Apache + mod_php | MySQL 8.4 LTS | yok | MySQL bekleyen sunucu ve araçlar |
| `mariadb-redis` (varsayılan) | Apache + mod_php | MariaDB 11.8 LTS | Redis 8.8 | WooCommerce, üyelik, yoğun yönetim paneli. Kökte de var: `docker-compose.yml` (Coolify) ve `docker-compose.local.yml` + `.env.example` (düz Docker) |
| `nginx-fpm` | nginx 1.30 + PHP-FPM | MariaDB 11.8 LTS | Redis 8.8 | GB RAM başına en çok trafik |
| `wpapi` | Apache + mod_php | MariaDB 11.8 LTS | Redis 8.8 | `mariadb-redis` + aynı compose'da WhatsApp API (flx-wpapi), WordPress'e hazır bağlı. Bkz. [WordPress + WhatsApp API](#wordpress--whatsapp-api) |

Her yığının iki hali var:

- `coolify/<yığın>/docker-compose.yml`: Coolify'a yapıştırılan kısa, tek dosya. Şifreleri Coolify üretir.
- `compose/<yığın>/`: `.env.example` ile düz Docker Compose. Docker olan her sunucuda çalışır.

## Coolify

**Git'ten (varsayılan yığın):** **Projects › Add Resource › Public Repository** › `https://github.com/FluxWP/docker-wordpress`, branch `main`, build pack **Docker Compose**, **Base Directory** `/`, **Docker Compose Location** `/docker-compose.yml`. Coolify `docker-compose.yml` dosyasını (MariaDB + Redis) okur ve alan adını kendisi üretir. Bu repo yalnız Docker yığını değişince değişir; FluxWP/FluxWP'deki eklenti ve tema release'leri siteyi yeniden deploy etmez. Daha sıkı istersen **Automatic Deployment**'ı kapat, Deploy'a kendin bas. Deploy.

> Bu yığınlar kısa süre `FluxWP/FluxWP` içinde `docker/` altındaydı; eklenti/tema güncellemeleri Docker sitelerini yeniden deploy etmesin diye buraya döndü. İmajlar (`ghcr.io/fluxwp/wordpress:*`) ve etiketleri değişmedi; monorepoya bağlı bir Coolify uygulamasında yalnız repo, Base Directory (`/`) ve Watch Paths (boşalt) değişir.

**Başka bir yığın ya da Git'siz:**

1. **Projects › Add Resource › Docker Compose Empty**.
2. `coolify/<yığın>/docker-compose.yml` içeriğini yapıştır, **Save**.
3. Coolify `wordpress` servisine (`nginx-fpm`'de `nginx`) alan adı üretir; istersen kendi alan adını gir.
4. **Deploy** et. Worker ilk açılışta WordPress'i kurar (`WP_AUTO_INSTALL` varsayılanı `true`). FluxWP Panel (flx-setup) kurulu gelir, ilk girişte kurulum sihirbazı açılır. `WP_ADMIN_PASSWORD` boşsa üretilen parola loga yazılmaz. Oku: `docker compose exec worker cat /run/flx-private/admin-password`. İlk girişten sonra parolayı değiştir, sonra dosyayı sil: `docker compose exec worker rm /run/flx-private/admin-password`.
5. İsteğe bağlı, **Environment Variables** içinde:
   - `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`, `WP_ADMIN_EMAIL`: üretilen parola ve `admin@<host>` yerine bu hesabı kullan.
   - `WP_AUTO_INSTALL=false`: worker kurulum yapmasın. `WP_INSTALL_TOKEN` (yalnız harf ve rakam, örneğin `openssl rand -hex 24`) yoksa `/wp-admin/install.php` ve `/wp-admin/setup-config.php` kapalı kalır. Tarayıcıda bir kez `/wp-admin/install.php?wp_install_token=...` aç; sorgu dizesi erişim günlüğüne düşer, otomasyon için `X-WP-Install-Token` başlığını kullan. Kurulum bitince `WP_INSTALL_TOKEN` değerini kaldır; sorgu dizesindeki jeton Apache erişim günlüğüne düşebilir.
   - `FLUXWP_SETUP=false`: FluxWP Panel'i (flx-setup) kurmaz.
   - `WP_LOCALE=tr_TR`, `WP_TIMEZONE=Europe/Istanbul`, `WP_TITLE`, PHP limitleri (aşağıda). Değiştirdikten sonra yeniden deploy et.

## Docker Compose

Varsayılan yığınla (`mariadb-redis`) hızlı başlangıç:

```bash
git clone --depth 1 https://github.com/FluxWP/docker-wordpress.git fluxwp
cd fluxwp
cp .env.example .env
# DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD gir: openssl rand -base64 24
docker compose -f docker-compose.local.yml up -d
```

Başka bir yığın:

```bash
cd fluxwp/compose/mariadb        # ya da mysql, mariadb-redis, nginx-fpm
cp .env.example .env
# DB_PASSWORD, DB_ROOT_PASSWORD (ve REDIS_PASSWORD) gir: openssl rand -base64 24
docker compose up -d
```

Site `http://localhost:8080` adresinde açılır (`HTTP_PORT`). Canlıda önüne HTTPS'li bir reverse proxy koy (Caddy, Traefik, nginx). WordPress HTTPS'i `X-Forwarded-Proto`'dan anlar; proxy özel ağdaysa ziyaretçinin gerçek IP'si `X-Forwarded-For`'dan okunur.

`WP_AUTO_INSTALL` varsayılanı `true`. `WP_URL` doluysa ilk açılışta WordPress kurulur. `WP_ADMIN_PASSWORD` boşsa parola yalnız `/run/flx-private/admin-password` dosyasına yazılır (mod `0600`, yalnız worker okur):

```bash
docker compose exec worker cat /run/flx-private/admin-password
```

İlk girişten sonra parolayı değiştir, sonra dosyayı sil:

```bash
docker compose exec worker rm /run/flx-private/admin-password
```

## WordPress + WhatsApp API

`wpapi` yığını, `mariadb-redis` + `wpapi` servisi olarak [FluxWP WhatsApp API](https://github.com/FluxWP/whatsapp-api) (flx-wpapi, Evolution API v2 uyumlu WhatsApp köprüsü). Diğer yığınlar değişmez; WhatsApp'ı aynı sunucuda istediğinde bunu kullan.

- **Tek ortak anahtar.** Verdiğin tek gizli değer `WPAPI_KEY`. `wpapi` konteyneri bunu `FLXW_API_KEY` olarak okur, WordPress `WPAPI_KEY` olarak alır. Coolify üretir; düz Docker'da `openssl rand -hex 32`.
- **İç ağ.** WordPress `http://wpapi:3000` adresine bağlanır. Servis port yayınlamaz, dışarıdan erişilemez. `FLXW_ALLOW_PRIVATE_URLS=1`, köprünün Docker ağında WordPress'e webhook ve medya için geri çağrı yapmasına izin verir.
- **Elle bağlantı yok.** Flux Mail etkinken FluxWP Docker mu-plugin'i bu adres, anahtar ve instance adıyla (`WPAPI_INSTANCE`, varsayılan `main`) bir WhatsApp bağlantısı (tür Flux WPAPI) ekler. Anahtarı ya da adresi değiştirirsen bağlantı sonraki istekte güncellenir.
- **Aynı veritabanı.** Köprü verisini yığının MariaDB'sinde (`flxw_*` tabloları), ayarlarını `wpapi-data` volume'unda tutar. Numara eşleme: Flux Mail › Bağlantılar › WhatsApp bağlantısı › hesap ekle › QR'ı okut (WhatsApp › Bağlı cihazlar).
- Opsiyonel: `WPAPI_MSG_KEEP_DAYS` (mesaj geçmişi, varsayılan 45) ve yedek panel için `WPAPI_PANEL=on` (port yayınla ya da proxy'le; ilk giriş `docker compose logs wpapi` içindeki kurulum token'ını ister).

```bash
cd compose/wpapi
cp .env.example .env
# DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD, WPAPI_KEY değerlerini yaz
docker compose up -d
```

Coolify: `coolify/wpapi/docker-compose.yml` içeriğini **Docker Compose Empty**'ye yapıştır. İmaj `ghcr.io/fluxwp/wordpress:wpapi-node22` (`FluxWP/whatsapp-api` `main`'den build edilir, her gün yenilenir). Baileys resmi olmayan WhatsApp Web protokolünü kullanır; kaybetmeyi göze alabileceğin bir numara kullan, toplu mesajdan kaçın.

## İmajlar

Ayarlar imajlarımızın içinde, compose dosyalarında yalnız servisler ve env değişkenleri var:

| İmaj | Tabanı | Ekledikleri |
|---|---|---|
| `ghcr.io/fluxwp/wordpress:7.1-php8.4-apache` | `wordpress:7.1-php8.4-apache` | PHP/OPcache ayarları, Apache sıkılaştırma ve gerçek IP, `wp-config-extra.php` |
| `ghcr.io/fluxwp/wordpress:7.1-php8.4-fpm` | `wordpress:7.1-php8.4-fpm` | Aynısı + PHP-FPM havuzu |
| `ghcr.io/fluxwp/wordpress:worker-php8.4` | `wordpress:cli-2.12-php8.4` | Worker script'i |
| `ghcr.io/fluxwp/wordpress:nginx-1.30` | `nginx:1.30-alpine` | WordPress için nginx ayarı |
| `ghcr.io/fluxwp/wordpress:wpapi-node22` | `node:22-alpine` | FluxWP WhatsApp API (flx-wpapi) |
| `ghcr.io/fluxwp/wordpress:realtime-bun1.4` | `oven/bun:1.4-alpine` | FluxWP Realtime WebSocket sunucusu |

amd64 ve arm64 için üretilir, her gün yeniden build edilir; PHP ve işletim sistemi güvenlik yamaları bir sonraki pull'da gelir. Sabitlemek istersen her build'in tarihli etiketi de var (`7.1-php8.4-apache-20261105`). Bilinen kritik açığı olan PHP sürümünde build başarısız olur.

Kendi imajını build etmeden bir dosyayı değiştirmek için kendi dosyanı üstüne mount et, ör. `./benim.ini:/usr/local/etc/php/conf.d/zz-flux.ini:ro`.

## Coolify'ın hazır şablonuna göre farklar

| Hazır şablon | Burada |
|---|---|
| `wordpress:latest`, `mariadb:11` | Sabit: `wordpress:7.1-php8.4-*`, `mariadb:11.8`, `mysql:8.4`, `redis:8.8-alpine`, `nginx:1.30-alpine`. Yama sürümleri pull ile gelir, büyük/küçük sürüm geçişi senin kararın. |
| PHP varsayılanları (2 MB yükleme, 128 MB bellek) | 128 MB yükleme, 256 MB bellek, 300 sn çalışma süresi, 5000 input var; hepsi env'den |
| İmaj varsayılanı OPcache | 256 MB, 30 bin dosya, 32 MB interned strings, JIT kapalı (WordPress'e faydası yok) |
| Healthcheck her 2 sn'de ana sayfayı açıyor (her yoklamada PHP + DB) | 15 sn'de bir statik dosya (Apache) ya da nginx üzerinden FPM ping, `start_period` ile |
| Koşulsuz `depends_on` | Veritabanı ve Redis sağlıklı olana kadar bekler |
| Sayfa açılışında WP-Cron | `DISABLE_WP_CRON` + worker 60 sn'de bir zamanı gelen görevleri çalıştırır; trafiksiz sitede de cron işler |
| Deploy sonrası kurulum ekranı açık | Kurulum ekranı kapalı. Otomatik kurulum varsayılan. Üretilen parola yalnız worker'ın okuduğu dosyada |
| MariaDB varsayılanları | utf8mb4, 256 MB buffer pool, `skip-name-resolve`, performance schema kapalı (~100-400 MB RAM tasarrufu), `MARIADB_AUTO_UPGRADE` |
| - | PHP 8.4.27+ (PHP2Shell, CVE-2026-109618/109619 yamalı) ve `session.upload_progress` kapalı (doğrulanmış sömürü yolu) |
| - | `DISALLOW_FILE_EDIT`, `no-new-privileges`, DB/Redis portları dışa kapalı, sunucu sürümü gizli, `wp-config.php`/`readme.html`/uploads içinde PHP engelli, proxy arkasında gerçek IP |

## Ayarlar

Hepsi isteğe bağlı. Coolify'da environment variable, Compose'da `.env` içine.

| Değişken | Varsayılan | |
|---|---|---|
| `PHP_MEMORY_LIMIT` | `256M` | `WP_MEMORY_LIMIT` de bu |
| `PHP_UPLOAD_MAX_FILESIZE` | `128M` | Tek dosyada en büyük yükleme |
| `PHP_POST_MAX_SIZE` | `128M` | Yükleme boyutundan küçük olmasın |
| `PHP_MAX_EXECUTION_TIME` | `300` | Saniye |
| `PHP_OPCACHE_MEMORY` | `256` | MB |
| `PHP_TIMEZONE` | `UTC` | PHP `date.timezone` |
| `APACHE_MAX_WORKERS` | `20` | Apache yığınları: en çok süreç (her biri ~64 MB) |
| `PHP_FPM_MAX_CHILDREN` | `10` | nginx-fpm: en çok PHP worker (her biri ~64 MB, en az 4) |
| `DB_BUFFER_POOL_SIZE` | `256M` | Veritabanına ayırdığın RAM'in ~%50'si |
| `DB_MAX_CONNECTIONS` | `100` | |
| `REDIS_MAXMEMORY` | `128mb` | Yalnız önbellek, kalıcılık yok, LRU |
| `WP_URL`, `WP_TITLE`, `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`, `WP_ADMIN_EMAIL` | | Otomatik kurulum (Coolify adresi doldurur). Parola boşsa üretilir |
| `WP_URL_SYNC` | `true` | Alan adı değişince worker veritabanını `WP_URL`'e uydurur ([Alan adını değiştirme](#alan-adını-değiştirme)). `false` kapatır |
| `WP_AUTO_INSTALL` | `true` | İlk açılışta `WP_ADMIN_EMAIL` olmadan kur (e-posta `admin@<host>`). `false` worker kurulumunu atlar |
| `WP_INSTALL_TOKEN` | | `/wp-admin/install.php` ve `/wp-admin/setup-config.php` için harf-rakam jeton (en çok 128). Boşsa ikisi de kapalı. Kurulum bitince kaldır; sorgu dizesindeki jeton Apache erişim günlüğüne düşebilir |
| `WP_LOCALE` | `en_US` | İlk kurulumda yüklenecek dil, ör. `tr_TR` |
| `WP_TIMEZONE` | `UTC` | İlk kurulumda site saat dilimi |
| `FLUXWP_SETUP` | `true` | FluxWP Panel'i (flx-setup) kurar, ilk wp-admin girişinde sihirbazını açar; `false` kurmaz |
| `FLUXWP_SETUP_URL` | | Özel zip; `https` olmalı. Varsayılan önce `updater.fluxwp.com`, sonra GitHub release |
| `FLUXWP_SETUP_SHA256` | | Flux Setup zip'i için isteğe bağlı SHA-256 pini. Boşsa pin yok |
| `WP_CRON_INTERVAL` | `60` | Her turun ardından periyodik cron yedek aralığı |
| `WP_BACKGROUND_POLL_INTERVAL` | `2` | Hafif ortak dosya bildirimi kontrol aralığı; boşta PHP açılmaz |
| `WP_BACKGROUND_WORKERS_PER_PLUGIN` | `3` | Eklenti başına eşzamanlı kayıtlı Flux arka plan işi |
| `WP_BACKGROUND_MAX_PROCESSES` | `9` | Bu işler için site genelindeki toplam üst sınır |
| `WP_ENVIRONMENT_TYPE` | `production` | |
| `WORDPRESS_TABLE_PREFIX` | `wp_` | Yalnız ilk kurulumdan önce |

Gerisi için: `WORDPRESS_CONFIG_EXTRA` (`wp-config.php`'ye eklenen PHP; içindeki varsayılan `require` satırını koru) ya da `config/` içindeki bir dosyanın mount edilmiş kopyası.

## Worker

`worker` servisi `wordpress:cli` çalıştırır ve tekrarlansa da zararsız dört iş yapar:

1. WordPress dosyalarını ve veritabanını bekler.
2. İlk açılışta: WordPress'i kurar (`WP_AUTO_INSTALL` varsayılanı `true`; adres için `WP_ADMIN_EMAIL`, yoksa `admin@<host>`), dili, Redis Object Cache eklentisini ve drop-in'ini (Redis yığınları), FluxWP Panel'i (`FLUXWP_SETUP`, varsayılan açık) kurar. Zip yalnız HTTPS ile iner, boyutu sınırlıdır ve içinde `flx-setup/flx-setup.php` varsa, dışarı kaçan yol yoksa kurulur. WordPress kurulur kurulmaz etkindir ve ilk wp-admin girişinde kurulum sihirbazı açılır. Dil ve FluxWP Panel bir kez yapılır; wp-admin'de sonradan değiştirdiğin korunur. Üretilen yönetici parolası loga değil, `/run/flx-private/admin-password` dosyasına yazılır (mod `0600`, yalnız worker okur). Oku: `docker compose exec worker cat /run/flx-private/admin-password`. İlk girişten sonra parolayı değiştir, sonra dosyayı sil: `docker compose exec worker rm /run/flx-private/admin-password`.
3. Alan adı değişimini izler: `WP_URL` (Coolify'daki alan adı) WordPress'teki adresten farklıysa `home`/`siteurl` güncellenir, eski adres veritabanında bir kez değiştirilir. Bkz. [Alan adını değiştirme](#alan-adını-değiştirme).
4. Ortak dosya bildirimlerini varsayılan 2 saniyede bir kontrol ederek zamanı gelen cron işlerini çalıştırır; `WP_CRON_INTERVAL` periyodik yedek tetiklemesi korunur. Boşta yapılan kontroller PHP veya WordPress başlatmaz.


Mail, Certificates ve Backup içine gömülü ortak kütüphane bağımsız işleri paralel çalıştırır: varsayılan **eklenti başına 3**, **site toplamında en fazla 9 aktif iş**. Coolify ortam değişkenleri panelinden veya Compose `.env` dosyasından `WP_BACKGROUND_WORKERS_PER_PLUGIN` ve `WP_BACKGROUND_MAX_PROCESSES` değiştirildikten sonra worker yeniden oluşturulmalıdır. Bunlar kapasite sınırlarıdır; her sunucunun dokuz ağır işi kaldırdığı anlamına gelmez. Kaynak kilitleri, posta kutusu kotaları ve tek aktif yedek kuralı korunur. Kayıtlı olmayan üçüncü taraf cron işleri mevcut akışında kalır.

Hızlı uyandırma güncel worker imajını ve uyumlu ortak kütüphane paketlerini gerektirir; eski sürümler 60 saniyelik cron ile devam eder. Bildirim dosyaları yalnız zaman damgası içerir; mesaj veya alıcı bilgisi taşımaz. Boş kapasite varsa hazır iş kısa bir kontrol ve WordPress açılış süresi ardından başlayabilir; dolu havuz, ileri tarih, gönderim kotası veya yeniden deneme beklemesi yine gecikme yaratabilir. Docker olmayan normal WordPress kurulumlarında mevcut zamanlayıcı devam eder.

WP-CLI de orada:

```bash
docker compose exec worker wp plugin list
docker compose exec worker wp search-replace 'http://eski.test' 'https://yeni.example' --all-tables
```

`wp db cli/export/import` cli imajındaki MariaDB istemcisini kullanır; `mysql` yığınında bunun yerine `mysql` konteynerini kullan (aşağıda).

## Realtime (canlı güncelleme)

Redis'li yığınlarda (`mariadb-redis`, `nginx-fpm`, `wpapi`) küçük bir `realtime` servisi var (~25 MB RAM). WordPress kısa "bu değişti" sinyallerini Redis'e yayınlar, realtime servisi bunları WebSocket ile oturum açmış FluxWP paneline ve FluxApp'e iletir. Böylece gelen kutuları, rozetler ve bildirimler bir sonraki polling'i beklemeden anında güncellenir. Sinyal içerik taşımaz: istemci veriyi yine aynı yetkilerle WordPress'ten çeker.

Zorunlu değil. Servis kapalıysa, silinmişse ya da secret boşsa istemciler polling ile devam eder, başka hiçbir şey ona bağlı değil.

- **Coolify:** ayar yok. Secret üretilir (`SERVICE_PASSWORD_64_REALTIME`), servis sitenin kendi domaininde `/flx-rt` yolunda çalışır. Apache yığınında WordPress domainini değiştirirsen `realtime` servisinin domainini de `https://<domain>/flx-rt` yap. `nginx-fpm`'de `/flx-rt`'yi nginx kendisi sunar.
- **Düz Docker:** `FLX_REALTIME_SECRET` ver (`openssl rand -hex 32`). `nginx-fpm` `/flx-rt`'yi kendisi sunar. Apache yığınlarında reverse proxy'de `/flx-rt` yolunu (WebSocket) `127.0.0.1:${REALTIME_PORT:-8081}`'e yönlendir ve `FLX_REALTIME_URL=/flx-rt` yap.
- **Kontrol:** `docker compose exec worker wp flx realtime status` ve `wp flx realtime test` (WordPress → Redis → realtime).
- Giriş: istemci WordPress'ten 60 saniyelik, tek kullanımlık bir ticket alır ve WebSocket'in ilk mesajında gönderir (URL'de asla). Çıkış, rol ve şifre değişikliği kullanıcının açık bağlantılarını kapatır.
- Paket geliştiricileri için: `flx-realtime.php` mu-plugin'inde `flx_realtime_publish()`, `flx_realtime_online()` ve `flx_realtime_topics` filtresi var; başlığına bak.
- **Ziyaretçiler (giriş yapmamış):** aynı sunucu site ziyaretçilerine de hizmet verir; biletleri yalnız WordPress'in yazdığı konuları taşır (kullanıcı ve yetki yok, aynı anda `RT_MAX_VISITORS` soket, varsayılan 300). Ziyaretçiyi kendiniz doğruladıktan sonra `flx_realtime_visitor_ticket( array( 'chatbot.conv.42' ) )`; yalnız o soketlere ulaşmak için `flx_realtime_publish( $konu, $veri, array( 'topic' => $konu ) )`. Herkese açık konular (`public.*`, ör. canlı bağış toplamı) `flx_realtime_public_topics` filtresiyle listelenir; sonra herkes `POST /wp-json/flx-realtime/v1/public-ticket` `{ "topics": [...] }` ile bilet alabilir (adres başına dakikada 30). Herkese açık sinyalin içeriği herkesin görebileceği türden olmalı. Flux AI Chatbot widget'ı yanıtları anında göstermek için bunu kullanır.

## Yedek ve geri yükleme

```bash
# veritabanı (MariaDB yığınları)
docker compose exec -T mariadb sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > yedek.sql
docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < yedek.sql
# veritabanı (mysql yığını)
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > yedek.sql
# dosyalar (wp-content)
docker compose exec worker tar -C /var/www/html -czf - wp-content > wp-content.tgz
```

Coolify veritabanı servisini zamanlanmış olarak da yedekleyebilir.

## Alan adını değiştirme

Coolify'da alan adını (ya da `.env`'de `WP_URL`) değiştirip yeniden deploy edin. Başka bir şey gerekmez: worker açılırken `WP_URL` ile WordPress'teki `home`/`siteurl` değerini karşılaştırır. Farklıysa

1. `site address changed: <eski> -> <yeni>` yazar,
2. `wp search-replace` ile eski adresi tüm tablolarda yenisiyle değiştirir (serialize veri bozulmaz, `guid` sütunu atlanır, blok içeriğindeki JSON kaçışlı kopyalar dahil),
3. object cache'i temizler ve yeni adresi `flx_docker_url` seçeneğine yazar; böylece her değişiklikte bir kez çalışır.

Takip: `docker compose logs worker` (Coolify'da worker servisinin logları). wp-admin'den elle yaptığınız değişiklik sonraki açılışlarda geri alınmaz; worker yalnız `WP_URL`'in kendisi değişince çalışır. Kapatmak için `WP_URL_SYNC=false`. Yolu olan bir `WP_URL` (`https://ornek.com/blog`) otomatik eşitlenmez; elle yapın.

Site hâlâ eski alan adına yönleniyorsa sırayla bakın:

- `docker compose logs worker` içinde `site address sync failed` yok mu.
- `WORDPRESS_CONFIG_EXTRA` ya da `wp-config.php` içindeki `WP_HOME` / `WP_SITEURL` veritabanını ezer. Worker bunları uyarır; kaldırın ya da güncelleyin.
- Yönlendirme ya da önbellek eklentisi eski adresi tutuyor olabilir; önbelleği temizleyin.
- Cloudflare gibi proxy yeni alan adını gösteriyor ve SSL modu Full (strict) olmalı.

Elle düzeltme. cli imajında WP-CLI hazırdır, worker'dan çalıştırın:

```bash
docker compose exec worker wp option update home 'https://yeni.example'
docker compose exec worker wp option update siteurl 'https://yeni.example'
docker compose exec worker wp search-replace 'https://eski.example' 'https://yeni.example' --all-tables --skip-columns=guid
docker compose exec worker wp cache flush
```

Coolify'da `docker compose exec worker` yerine `worker` servisinin Terminal'ini açın. `wordpress` konteynerinin içindeyseniz orada WP-CLI yoktur (`wp: command not found`). Bir kez indirin, bir sonraki redeploy'a kadar durur:

```bash
cd /tmp
curl -fsSLO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar --allow-root --path=/var/www/html option update home 'https://yeni.example'
php wp-cli.phar --allow-root --path=/var/www/html option update siteurl 'https://yeni.example'
php wp-cli.phar --allow-root --path=/var/www/html search-replace 'https://eski.example' 'https://yeni.example' --all-tables --skip-columns=guid
php wp-cli.phar --allow-root --path=/var/www/html cache flush
```

Önce veritabanı yedeği alın (yukarıda). Değiştirmeden sayıları görmek için `search-replace`'e `--dry-run` ekleyin.

## Güncellemeler

- **WordPress çekirdeği, eklentiler, temalar**: her zamanki gibi wp-admin'den (küçük çekirdek güncellemeleri otomatik). İmaj etiketi yalnız PHP sürümünü ve ilk kurulumun dosyalarını belirler.
- **İmajlar**: yeniden deploy et (Coolify) ya da `docker compose pull && docker compose up -d`, son günlük build gelir. Yeni PHP/WordPress dalları yeni etiket olarak gelir.
- **MariaDB**: yeni etikete geçince `MARIADB_AUTO_UPGRADE=1` yükseltme adımını çalıştırır. Önce yedek al.

## Geliştirme

- `config/`, `scripts/`: imajlara giren dosyalar (`images/*/Dockerfile`).
- `tools/build.py`: imaj etiketleri ve compose üretici.

```bash
python3 tools/build.py          # coolify/* ve compose/* yeniden üret
python3 tools/build.py --check  # CI'ın çalıştırdığı
docker build -f images/wordpress/Dockerfile --build-arg BASE=wordpress:7.1-php8.4-apache -t ghcr.io/fluxwp/wordpress:7.1-php8.4-apache .
```

Komutları repo kökünde çalıştır. Kaynakları düzenle, üretilen compose dosyalarını değil. CI (`.github/workflows/validate.yml`) PR'daki imajları build edip her yığını çalıştırır; `.github/workflows/images.yml` imaj, config ya da script'e dokunan her `main` push'unda ve her gün (upstream güvenlik yamaları için) `ghcr.io/fluxwp/wordpress` (amd64 + arm64) yayınlar.

## Bakımcılar

FluxWP Docker'i **Ahmet Yılmaz** ([@sharkpaw](https://github.com/sharkpaw)) yönetir ve geliştirir.

<table><tr><td align="center"><a href="https://github.com/sharkpaw"><img src="https://github.com/sharkpaw.png?size=120" width="64" alt="Ahmet Yılmaz"><br><b>Ahmet Yılmaz</b></a><br><sub>Proje lideri · Bakımcı</sub></td></tr></table>

### Katkıda bulunanlar

Issue ve pull request'lere açığız. Katkıda bulunanlar burada listelenecek.

## Lisans

[MIT](LICENSE). WordPress'in ve kullanılan imajların kendi lisansları geçerlidir.
