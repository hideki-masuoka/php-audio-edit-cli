FROM --platform=linux/amd64 dunglas/frankenphp:static-builder-gnu

# Copy application files into the embedding directory
WORKDIR /go/src/app/dist/app
COPY . .

# Run composer install to ensure production-optimized vendor autoloader
# (Excluding development dependencies and dev test files if any)
RUN rm -rf vendor composer.lock \
    && composer install --no-dev --optimize-autoloader

# Return to FrankenPHP source directory and compile with EMBED
WORKDIR /go/src/app/
RUN EMBED=dist/app/ ./build-static.sh
