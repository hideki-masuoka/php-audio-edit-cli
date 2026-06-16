# Stage 1: Build the PHAR package using Box
FROM composer:latest AS vendor-builder
WORKDIR /app

# Copy dependency definitions
COPY composer.json composer.lock ./

# Install production dependencies
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Copy application source files
COPY . .

# Download and install Humbug Box to compile the Phar
ADD https://github.com/box-project/box/releases/download/4.6.2/box.phar /usr/local/bin/box
RUN chmod +x /usr/local/bin/box

# Build the phar file
RUN php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" -d phar.readonly=0 /usr/local/bin/box compile


# Stage 2: Compile the static PHP binary and combine with PHAR using static-php-cli
FROM debian:12-slim AS php-builder

# Install required build packages
RUN apt-get update && apt-get install -y \
    curl \
    ca-certificates \
    git \
    unzip \
    sudo


WORKDIR /build

# Download static-php-cli binary
RUN curl -fsSL -o spc https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-linux-x86_64 && chmod +x spc

# Run doctor --auto-fix to install all required compiler toolchains and system library headers
RUN ./spc doctor --auto-fix

# Download PHP source code and build dependencies
RUN ./spc download --with-php=8.4 --for-extensions="iconv,mbstring,openssl,phar,posix,pcntl,zip,zlib"



# Install UPX for binary packing/compression
RUN ./spc install-pkg upx

# Build micro SAPI with target extensions (iconv, mbstring, openssl, phar, posix, pcntl, zip, zlib)
# Use --with-micro-fake-cli so PHP_SAPI reports 'cli' instead of 'micro' (ensures maximum compatibility with Symfony components)
# Use --with-upx-pack to compress the final binary using UPX
RUN ./spc build "iconv,mbstring,openssl,phar,posix,pcntl,zip,zlib" --build-micro --with-micro-fake-cli --with-upx-pack


# Copy the generated PHAR from Stage 1
COPY --from=vendor-builder /app/dist/audio-edit.phar ./audio-edit.phar

# Combine the micro.sfx runtime and the PHAR file to create the final standalone binary
RUN ./spc micro:combine audio-edit.phar -O /build/audio-edit-cli
