FROM php:8.2-apache

# Install Python 3, pip, and system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    python3-pip \
    python3-venv \
    curl \
    git \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite and headers, allow .htaccess overrides and set DirectoryIndex
RUN a2enmod rewrite headers && \
    echo "DirectoryIndex index.php index.html" >> /etc/apache2/apache2.conf && \
    sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Install Python dependencies
COPY requirements.txt /var/www/html/requirements.txt
RUN pip3 install --no-cache-dir --break-system-packages -r requirements.txt

# Copy PHP Web Suite & Application Files
COPY php/ /var/www/html/
COPY index.php /var/www/html/index.php
COPY index.html /var/www/html/index.html
COPY gyftr.py /var/www/html/gyftr.py
COPY tg_bot.py /var/www/html/tg_bot.py

# Configure sessions directory with full permissions
RUN mkdir -p /var/www/html/sessions/accounts && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 777 /var/www/html/sessions

# Entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/entrypoint.sh"]
