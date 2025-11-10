# Use official PHP + Apache image
FROM php:8.2-apache


# Enable Apache modules
RUN a2enmod rewrite headers


# Set working directory
WORKDIR /var/www/html


# Copy app files
COPY . /var/www/html


# Create writable files & set permissions
RUN touch /var/www/html/error.log \
&& chown -R www-data:www-data /var/www/html \
&& chmod 775 /var/www/html \
&& chmod 664 /var/www/html/users.json /var/www/html/error.log


# Hardening: don't expose version
RUN sed -i 's/ServerTokens OS/ServerTokens Prod/' /etc/apache2/conf-available/security.conf && \
sed -i 's/ServerSignature On/ServerSignature Off/' /etc/apache2/conf-available/security.conf


# Apache env var so Render health checks hit /
ENV APACHE_DOCUMENT_ROOT=/var/www/html


# Expose port (Render auto-detects)
EXPOSE 10000


# Run Apache on Render's $PORT
# Render sets $PORT; we reconfigure apache to listen on it at runtime
CMD ["bash", "-lc", "sed -i 's/Listen 80/Listen ${PORT:-10000}/' /etc/apache2/ports.conf && apache2-foreground"]
