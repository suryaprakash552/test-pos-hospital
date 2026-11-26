
<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://travis-ci.org/laravel/framework"><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 2000 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Cubet Techno Labs](https://cubettech.com)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[Many](https://www.many.co.uk)**
- **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
- **[DevSquad](https://devsquad.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[OP.GG](https://op.gg)**
- **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
- **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Installation

After installation, create an admin account through the application's registration process or use database seeders to set up initial credentials.


Flutter app

cd /home/arjun/Documents/pos-inventory-web/mtl_pos

flutter clean
flutter pub get
flutter analyze          # must be clean before you build
flutter test

# Production runs on dev.naayahs.com, so the build says so twice: once to be
# allowed to point there, and once to state that this is the build the shops
# run — without the second flag every till is branded "DEV DATA".
flutter build apk --release --split-per-abi \
  --dart-define=ALLOW_DEV_HOST=true \
  --dart-define=PRODUCTION_DEPLOYMENT=true

# A test build against the same host: drop PRODUCTION_DEPLOYMENT and it carries
# the ribbon again, which is the point of it.
# flutter build apk --release --split-per-abi --dart-define=ALLOW_DEV_HOST=true


# Play Store upload artifact
flutter build appbundle --release

# Direct-install / sideload APKs (split per ABI, smaller)
flutter build apk --release --split-per-abi
Outputs:

build/app/outputs/bundle/release/app-release.aab
build/app/outputs/flutter-apk/app-*-release.apk
Bump version: in pubspec.yaml before each Play upload — the build number must increase or Play rejects it.

iOS, if you need it:


flutter build ipa --release
Laravel backend

cd /home/arjun/Documents/pos-inventory-web

composer install --no-dev --optimize-autoloader
npm ci && npm run build          # Vite assets → public/build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link
To undo caching when you deploy again: php artisan optimize:clear.

Before you run any of that
Your .env still says:


APP_ENV=local
APP_DEBUG=true
config:cache will bake those in. APP_DEBUG=true in production leaks stack traces containing DB credentials and env values on every 500. Set these first:


APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-domain
And the two items still outstanding from the audit, both of which are production concerns rather than build steps:

Rotate the Google/Twitter credentials committed in b63ff8d. They're in git history, so changing .env alone doesn't help — the old keys must be revoked at the provider.
Schedule the idempotency_keys cleanup — the table only grows otherwise, and it carries a unique index that every checkout writes to.
One caveat I won't paper over: flutter analyze was last at ~280 errors from your in-flight app_dropdown.dart work. flutter build will fail on those. The analyze/test steps above are ordered first deliberately — don't skip them to get to the build.



## Dev login (dev.naayahs.com)

`training@gmail` / `Training@123` no longer works — the password does not match,
and that account owns business 19 ("Sample companyName"), which is empty. Signing
in with it shows a working app with no products, no sales and no stock, which
reads as "the APIs are broken" from the device.

The training catalogue (803 products) is business 13, SUHAA TRAINING. Its
shop-owner was lost — most likely to the `POST /api/v1/business-delete` testing
that failed on a foreign-key constraint — leaving 16 staff accounts and no owner,
so use this:

    email    : suhaatraining@gmail
    password : Training@123
    business : 13 (SUHAA TRAINING)
    role     : shop-owner

Staff logins for the same business follow the pattern `training1@123` …
`training15@123`; their permissions are narrower, so prefer the owner above when
testing product or report screens.

