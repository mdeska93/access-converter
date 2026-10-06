@echo off
setlocal
if not exist .env copy .env.example .env
where composer >nul 2>nul
if errorlevel 1 (
 echo Composer belum ditemukan. Install Composer terlebih dahulu: https://getcomposer.org/
 pause
 exit /b 1
)
composer install
php artisan key:generate
php artisan optimize:clear
echo.
echo Selesai. Edit ACCESS_DATABASE_PATH di .env lalu jalankan: php artisan serve
pause
