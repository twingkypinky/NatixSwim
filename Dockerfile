# Natix Swim - PHP 8.2 + PDO MySQL, dijalankan dengan server bawaan PHP (cukup untuk demo)
FROM php:8.2-cli

RUN docker-php-ext-install pdo pdo_mysql mysqli

WORKDIR /app
COPY . /app

# Di Railway, config/koneksi.php (yang tidak ikut GitHub) dibuat dari versi Railway.
# File koneksi.php di laptop/XAMPP tidak berubah.
RUN cp /app/config/koneksi.railway.php /app/config/koneksi.php

# Folder untuk file upload (logo, bukti pembayaran, dll.)
RUN mkdir -p /app/uploads /app/assets/uploads && chmod -R 777 /app/uploads /app/assets/uploads

# Railway memberi nomor port lewat variabel PORT
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app"]
RUN printf "display_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\n" > /usr/local/etc/php/conf.d/zz-prod.ini
