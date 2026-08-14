FROM php:8.2-cli

# Install ekstensi cURL yang dibutuhkan backend
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set direktori kerja
WORKDIR /app

# Copy seluruh file repositori ke direktori /app
COPY . /app

# Menjalankan PHP Built-in Server menggunakan PORT dinamis dari Railway
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} index.php"]
