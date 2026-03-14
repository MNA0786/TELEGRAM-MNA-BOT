FROM php:8.2-apache

# Environment Variables - All channels configured
ENV BOT_TOKEN=8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU
ENV API_ID=21944581
ENV API_HASH=7b1c174a5cd3466e25a976c39a791737
ENV ADMIN_ID=1080317415
ENV BOT_USERNAME=@EntertainmentTadkaBot

# Public Channels
ENV CHANNEL_1_ID=-1003181705395
ENV CHANNEL_1_NAME="Main Channel"
ENV CHANNEL_1_USERNAME="@EntertainmentTadka786"

ENV CHANNEL_2_ID=-1003614546520
ENV CHANNEL_2_NAME="Serial Channel"
ENV CHANNEL_2_USERNAME="@Entertainment_Tadka_Serial_786"

ENV CHANNEL_3_ID=-1002831605258
ENV CHANNEL_3_NAME="Theater Prints"
ENV CHANNEL_3_USERNAME="@threater_print_movies"

ENV CHANNEL_4_ID=-1002964109368
ENV CHANNEL_4_NAME="Backup Channel"
ENV CHANNEL_4_USERNAME="@ETBackup"

# Private Channels
ENV PRIVATE_CHANNEL_1_ID=-1003251791991
ENV PRIVATE_CHANNEL_1_NAME="Private Channel 1"

ENV PRIVATE_CHANNEL_2_ID=-1002337293281
ENV PRIVATE_CHANNEL_2_NAME="Private Channel 2"

# Request Group
ENV REQUEST_GROUP_ID=-1003083386043
ENV REQUEST_GROUP_USERNAME="@EntertainmentTadka7860"

# CSV Format - LOCKED PERMANENT
ENV CSV_FORMAT="movie_name,message_id,channel_id"

# System dependencies install
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    curl \
    && docker-php-ext-install zip mysqli

# Composer install
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Apache configuration
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN a2enmod rewrite headers

# Document root set
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# File permissions (SINGLE ENTRY)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && touch movies.csv users.json bot_stats.json requests.json error.log \
    && chmod 666 movies.csv users.json bot_stats.json requests.json error.log

# Create backups directory
RUN mkdir -p backups && chmod 777 backups

# Port expose (SINGLE ENTRY)
EXPOSE 80

# Health check (SINGLE ENTRY)
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start command
CMD ["apache2-foreground"]