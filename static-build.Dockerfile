# Stage 1: Install Composer dependencies using the official Composer image
FROM composer:latest AS vendor-builder
WORKDIR /app

# Copy Composer definition
COPY composer.json ./

# Install production dependencies (ignoring platform requirements for target OS/extensions)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Stage 2: Build the static FrankenPHP binary with embedded app
FROM --platform=linux/amd64 dunglas/frankenphp:static-builder-gnu

# Copy application files into the embedding directory
WORKDIR /go/src/app/dist/app
COPY . .

# Copy vendor directory from Stage 1 (overwriting any local vendor folder)
COPY --from=vendor-builder /app/vendor ./vendor

# Ensure any local composer.lock or temporary vendor from host is not causing issues
# and ensure our entrypoint scripts have proper line endings and permissions
RUN chmod +x bin/audio-edit

# Return to FrankenPHP source directory and compile with EMBED
WORKDIR /go/src/app/
RUN EMBED=dist/app/ ./build-static.sh
