<div align="center">

[English](README.md) · **Türkçe**

# FluxWP docker-wordpress

**Coolify** ve düz **Docker Compose** için canlıya hazır WordPress yığınları: ayarlı PHP ve OPcache, gerçek yükleme limitleri, healthcheck'ler, sabit imaj sürümleri, worker'da WP-Cron, isteğe bağlı Redis nesne önbelleği ve tek ayarla FluxWP Panel kurulumu.

![WordPress 7.1](https://img.shields.io/badge/WordPress-7.1-21759b?logo=wordpress&logoColor=white) ![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4?logo=php&logoColor=white) ![Coolify](https://img.shields.io/badge/Coolify-hazır-6b16ed) ![Docker Compose](https://img.shields.io/badge/Docker%20Compose-v2-2496ed?logo=docker&logoColor=white)

</div>

## Yığını seç

| Yığın | Web sunucusu | Veritabanı | Nesne önbelleği | Ne için |
|---|---|---|---|---|
| `mariadb` | Apache + mod_php | MariaDB 11.8 LTS | yok | Küçük siteler, Coolify'ın hazır şablonuna en yakın olan |
| `mysql` | Apache + mod_php | MySQL 8.4 LTS | yok | MySQL bekleyen sunucu ve araçlar |
| `mariadb-redis` (varsayılan) | Apache + mod_php | MariaDB 11.8 LTS | Redis 8.8 | WooCommerce, üyelik, yoğun yönetim paneli. Repo kökündeki `docker-compose.yml` de bu |
| `nginx-fpm` | nginx 1.30 + PHP-FPM | MariaDB 11.8 LTS | Redis 8.8 | GB RAM başına en çok trafik |

Her yığının iki hali var:

- `coolify/<yığın>/docker-compose.yml`: Coolify'a yapıştırılan kısa, tek dosya. Şifreleri Coolify üretir.
- `compose/<yığın>/`: `.env.example` ile düz Docker Compose. Docker olan her sunucuda çalışır.

## Coolify

1. **Projects › Add Resource › Docker Compose Empty**.
2. `coolify/<yığın>/docker-compose.yml` içeriğini yapıştır, **Save**.
3. Alan adını `wordpress` servisine gir (`nginx-fpm`'de `nginx` servisine).
4. İsteğe bağlı, **Environment Variables** içinde:
   - `WP_ADMIN_EMAIL`: WordPress ilk deploy'da kendiliğinden kurulur, kurulum ekranı internete hiç açık kalmaz. Yönetici kullanıcı adı ve şifresi Coolify'ın ürettiği `SERVICE_USER_WPADMIN` / `SERVICE_PASSWORD_WPADMIN`.
   - `FLUXWP_SETUP=true`: FluxWP Panel'i (flx-setup) kurar ve etkinleştirir.
   - `WP_LOCALE=tr_TR`, `WP_TIMEZONE=Europe/Istanbul`, `WP_TITLE`, PHP limitleri (aşağıda).
5. **Deploy**.

## Docker Compose

Varsayılan yığınla (`mariadb-redis`) hızlı başlangıç, repo kökünden:

```bash
git clone https://github.com/FluxWP/docker-wordpress.git
cd docker-wordpress
cp .env.example .env
# DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD gir: openssl rand -base64 24
docker compose up -d
```

Başka bir yığın:

```bash
cd docker-wordpress/compose/mariadb        # ya da mysql, mariadb-redis, nginx-fpm
cp .env.example .env
# DB_PASSWORD, DB_ROOT_PASSWORD (ve REDIS_PASSWORD) gir: openssl rand -base64 24
docker compose up -d
```

Site `http://localhost:8080` adresinde açılır (`HTTP_PORT`). Canlıda önüne HTTPS'li bir reverse proxy koy (Caddy, Traefik, nginx). WordPress HTTPS'i `X-Forwarded-Proto`'dan anlar; proxy özel ağdaysa ziyaretçinin gerçek IP'si `X-Forwarded-For`'dan okunur.

Kendiliğinden kurulum için `WP_URL` + `WP_ADMIN_EMAIL` gir. `WP_ADMIN_PASSWORD` boşsa rastgele şifre bir kez `docker compose logs worker` çıktısına yazılır.

## İmajlar

Ayarlar imajlarımızın içinde, compose dosyalarında yalnız servisler ve env değişkenleri var:

| İmaj | Tabanı | Ekledikleri |
|---|---|---|
| `ghcr.io/fluxwp/wordpress:7.1-php8.4-apache` | `wordpress:7.1-php8.4-apache` | PHP/OPcache ayarları, Apache sıkılaştırma ve gerçek IP, `wp-config-extra.php` |
| `ghcr.io/fluxwp/wordpress:7.1-php8.4-fpm` | `wordpress:7.1-php8.4-fpm` | Aynısı + PHP-FPM havuzu |
| `ghcr.io/fluxwp/wordpress:worker-php8.4` | `wordpress:cli-2.12-php8.4` | Worker script'i |
| `ghcr.io/fluxwp/wordpress:nginx-1.30` | `nginx:1.30-alpine` | WordPress için nginx ayarı |

amd64 ve arm64 için üretilir, her gün yeniden build edilir; PHP ve işletim sistemi güvenlik yamaları bir sonraki pull'da gelir. Sabitlemek istersen her build'in tarihli etiketi de var (`7.1-php8.4-apache-20261105`). Bilinen kritik açığı olan PHP sürümünde build başarısız olur.

Kendi imajını build etmeden bir dosyayı değiştirmek için kendi dosyanı üstüne mount et, ör. `./benim.ini:/usr/local/etc/php/conf.d/zz-flux.ini:ro`.

## Coolify'ın hazır şablonuna göre farklar

| Hazır şablon | Burada |
|---|---|
| `wordpress:latest`, `mariadb:11` | Sabit: `wordpress:7.1-php8.4-*`, `mariadb:11.8`, `mysql:8.4`, `redis:8.8-alpine`, `nginx:1.30-alpine`. Yama sürümleri pull ile gelir, büyük/küçük sürüm geçişi senin kararın. |
| PHP varsayılanları (2 MB yükleme, 128 MB bellek) | 128 MB yükleme, 256 MB bellek, 300 sn çalışma süresi, 5000 input var; hepsi env'den |
| İmaj varsayılanı OPcache | 192 MB, 30 bin dosya, 32 MB interned strings, JIT kapalı (WordPress'e faydası yok) |
| Healthcheck her 2 sn'de ana sayfayı açıyor (her yoklamada PHP + DB) | 15 sn'de bir statik dosya (Apache) ya da nginx üzerinden FPM ping, `start_period` ile |
| Koşulsuz `depends_on` | Veritabanı ve Redis sağlıklı olana kadar bekler |
| Sayfa açılışında WP-Cron | `DISABLE_WP_CRON` + worker 60 sn'de bir zamanı gelen görevleri çalıştırır; trafiksiz sitede de cron işler |
| Deploy sonrası kurulum ekranı açık | İsteğe bağlı otomatik kurulum, yönetici bilgileri üretilir |
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
| `PHP_OPCACHE_MEMORY` | `192` | MB |
| `PHP_TIMEZONE` | `UTC` | PHP `date.timezone` |
| `APACHE_MAX_WORKERS` | `20` | Apache yığınları: en çok süreç (her biri ~64 MB) |
| `PHP_FPM_MAX_CHILDREN` | `10` | nginx-fpm: en çok PHP worker (her biri ~64 MB, en az 4) |
| `DB_BUFFER_POOL_SIZE` | `256M` | Veritabanına ayırdığın RAM'in ~%50'si |
| `DB_MAX_CONNECTIONS` | `100` | |
| `REDIS_MAXMEMORY` | `128mb` | Yalnız önbellek, kalıcılık yok, LRU |
| `WP_URL`, `WP_TITLE`, `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`, `WP_ADMIN_EMAIL` | | Otomatik kurulum (Coolify adres, kullanıcı ve şifreyi kendisi doldurur) |
| `WP_LOCALE` | `en_US` | İlk kurulumda yüklenecek dil, ör. `tr_TR` |
| `WP_TIMEZONE` | `UTC` | İlk kurulumda site saat dilimi |
| `FLUXWP_SETUP` | `false` | `true` FluxWP Panel'i (flx-setup) kurar |
| `FLUXWP_SETUP_URL` | | Özel zip; varsayılan önce `updater.fluxwp.com`, sonra GitHub release |
| `WP_CRON_INTERVAL` | `60` | Cron çalıştırmaları arası saniye |
| `WP_ENVIRONMENT_TYPE` | `production` | |
| `WORDPRESS_TABLE_PREFIX` | `wp_` | Yalnız ilk kurulumdan önce |

Gerisi için: `WORDPRESS_CONFIG_EXTRA` (`wp-config.php`'ye eklenen PHP; içindeki varsayılan `require` satırını koru) ya da `config/` içindeki bir dosyanın mount edilmiş kopyası.

## Worker

`worker` servisi `wordpress:cli` çalıştırır ve tekrarlansa da zararsız üç iş yapar:

1. WordPress dosyalarını ve veritabanını bekler.
2. İlk açılışta: WordPress'i kurar (`WP_ADMIN_EMAIL` varsa), dili, Redis Object Cache eklentisini ve drop-in'ini (Redis yığınları), FluxWP Panel'i (`FLUXWP_SETUP=true`) kurar. Dil ve FluxWP Panel bir kez yapılır; wp-admin'de sonradan değiştirdiğin korunur.
3. Zamanı gelen WP-Cron görevlerini `WP_CRON_INTERVAL` saniyede bir çalıştırır.

WP-CLI de orada:

```bash
docker compose exec worker wp plugin list
docker compose exec worker wp search-replace 'http://eski.test' 'https://yeni.example' --all-tables
```

`wp db cli/export/import` cli imajındaki MariaDB istemcisini kullanır; `mysql` yığınında bunun yerine `mysql` konteynerini kullan (aşağıda).

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

## Güncellemeler

- **WordPress çekirdeği, eklentiler, temalar**: her zamanki gibi wp-admin'den (küçük çekirdek güncellemeleri otomatik). İmaj etiketi yalnız PHP sürümünü ve ilk kurulumun dosyalarını belirler.
- **İmajlar**: yeniden deploy et (Coolify) ya da `docker compose pull && docker compose up -d`, son günlük build gelir. Yeni PHP/WordPress dalları bu reponun yeni sürümünde yeni etiket olarak gelir.
- **MariaDB**: yeni etikete geçince `MARIADB_AUTO_UPGRADE=1` yükseltme adımını çalıştırır. Önce yedek al.

## Geliştirme

- `config/`, `scripts/`: imajlara giren dosyalar (`images/*/Dockerfile`).
- `tools/build.py`: imaj etiketleri ve compose üretici.

```bash
python3 tools/build.py          # coolify/* ve compose/* yeniden üret
python3 tools/build.py --check  # CI'ın çalıştırdığı
docker build -f images/wordpress/Dockerfile --build-arg BASE=wordpress:7.1-php8.4-apache -t ghcr.io/fluxwp/wordpress:7.1-php8.4-apache .
```

Kaynakları düzenle, üretilen compose dosyalarını değil. CI PR'daki imajları build edip her yığını çalıştırır.

## Bakımcılar

FluxWP docker-wordpress'i **Ahmet Yılmaz** ([@sharkpaw](https://github.com/sharkpaw)) yönetir ve geliştirir.

<table><tr><td align="center"><a href="https://github.com/sharkpaw"><img src="https://github.com/sharkpaw.png?size=120" width="64" alt="Ahmet Yılmaz"><br><b>Ahmet Yılmaz</b></a><br><sub>Proje lideri · Bakımcı</sub></td></tr></table>

### Katkıda bulunanlar

Issue ve pull request'lere açığız. Katkıda bulunanlar burada listelenecek.

## Lisans

[MIT](LICENSE). WordPress'in ve kullanılan imajların kendi lisansları geçerlidir.
