# FROM php:8.2-apache

# # Install PHP extensions required for typical MySQL/PDO usage
# RUN apt-get update \
#     && apt-get install -y --no-install-recommends libzip-dev unzip git \
#     && docker-php-ext-install pdo pdo_mysql \
#     && apt-get clean \
#     && rm -rf /var/lib/apt/lists/*

# WORKDIR /var/www/html

# # Copy application source into container
# COPY . /var/www/html

# # Ensure Apache can serve the application files
# RUN chown -R www-data:www-data /var/www/html

# EXPOSE 80
# CMD ["apache2-foreground"]

FROM php:8.2-apache

# تثبيت الإضافات المطلوبة
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# تحديد مجلد العمل
WORKDIR /var/www/html

# نسخ المشروع
COPY . /var/www/html

# تفعيل mod_rewrite (مهم للـ routing)
RUN a2enmod rewrite

# تغيير DocumentRoot إلى مجلد public
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# السماح باستخدام .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/AllowOverride None/s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# حل تحذير ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# إعطاء الصلاحيات
RUN chown -R www-data:www-data /var/www/html

# فتح البورت
EXPOSE 80

# تشغيل Apache
CMD ["apache2-foreground"]