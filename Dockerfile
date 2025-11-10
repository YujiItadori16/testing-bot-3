FROM php:8.2-apache
RUN a2enmod rewrite headers
WORKDIR /var/www/html
COPY . /var/www/html
RUN touch /var/www/html/error.log  && chown -R www-data:www-data /var/www/html  && chmod 775 /var/www/html  && chmod 664 /var/www/html/users.json /var/www/html/error.log
RUN sed -i 's/ServerTokens OS/ServerTokens Prod/' /etc/apache2/conf-available/security.conf &&     sed -i 's/ServerSignature On/ServerSignature Off/' /etc/apache2/conf-available/security.conf
ENV APACHE_DOCUMENT_ROOT=/var/www/html
EXPOSE 10000
CMD ["bash", "-lc", "sed -i 's/Listen 80/Listen ${PORT:-10000}/' /etc/apache2/ports.conf && apache2-foreground"]
