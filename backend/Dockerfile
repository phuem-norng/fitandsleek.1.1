FROM php:8.2-fpm

# ១. ដំឡើង System Dependencies សម្រាប់ PostgreSQL និង PHP Extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd \
    && rm -rf /var/lib/apt/lists/*

# ២. ដំឡើង Node.js (ជំនាន់ ១៨ LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && apt-get install -y nodejs

# ៣. ដំឡើង Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ៤. កំណត់ Working Directory
WORKDIR /app
COPY . .

# ៥. ដំឡើង PHP Dependencies និង Node Dependencies
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs \
    && if [ -d "node-khqr" ]; then cd node-khqr && npm install --omit=dev && cd ..; fi

# ៦. កំណត់ Permissions ឱ្យបានត្រឹមត្រូវ
RUN mkdir -p /app/storage/app/public /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# ៧. បើក Port 8000
EXPOSE 8000

# ៨. យុទ្ធសាស្ត្រដោះស្រាយ Transaction Lock: ប្រើ Semicolon (;) ជំនួស && ក្នុង CMD
# ការប្រើ ; ជួយឱ្យ Command បន្តដំណើរការទោះបីជាមាន Error តិចតួច និងជួយ Reset Connection
CMD php artisan storage:link --force; \
    php artisan db:wipe --force; \
    php artisan migrate --force --no-interaction; \
    php artisan config:cache; \
    php artisan route:cache; \
    php artisan view:cache; \
    php artisan serve --host=0.0.0.0 --port=8000
