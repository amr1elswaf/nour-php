# Nour framework image — FROM the local openswoole-php image which
# already has swoole + redis + mysqli + msgpack + binary_json compiled.
#
# Build:
#     docker build -t nour:latest F:/projects/nour
#
# Run (with the host app mounted at /app):
#     docker run --rm --network Nour \
#         -p 19501:9501 -p 19502:9502 -p 19503:9503 \
#         -v F:/projects/nour_php/nour_php:/app \
#         nour:latest

FROM openswoole-php:latest

# Bring in the framework source. The base image has its own ENTRYPOINT
# (`dumb-init -- php`); we just hand it a script via CMD.
COPY . /opt/nour

# Install composer (the base image doesn't have it on PATH for sure).
# If it already exists this is a no-op; if not, vendor will be missing
# until we run composer install.
RUN if ! command -v composer >/dev/null 2>&1; then \
        curl -sS https://getcomposer.org/installer \
            | php -- --install-dir=/usr/local/bin --filename=composer ; \
    fi \
    && cd /opt/nour \
    && composer install --no-dev --optimize-autoloader --no-interaction

# The host app gets mounted at /app at runtime. NOUR_APP_DIR tells the
# bundled entry where to look for data/setup.json + vendor/autoload.php.
ENV NOUR_APP_DIR=/app
WORKDIR /app

# bin/server.php loads the framework autoload from /opt/nour/vendor,
# then the app autoload from /app/vendor (if present), then calls
# Nour\Server\Boot::run('/app').
CMD ["/opt/nour/bin/server.php"]
