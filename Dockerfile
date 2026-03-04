FROM laravelsail/php82-composer

# Atualizar pacotes e instalar dependências necessárias
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    python3 \
    python3-pip \
    python3-pandas \
    python3-openpyxl \
    python3-xlrd \
    python3-numpy \
    python3-pypdf2 \
    && rm -rf /var/lib/apt/lists/*

# pdfplumber (extração PDF com layout correto; não disponível via apt)
RUN pip3 install pdfplumber --break-system-packages

# Instalar e habilitar extensões PHP necessárias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        bcmath \
        zip \
    && docker-php-ext-enable \
        pdo_mysql \
        gd \
        bcmath \
        zip

# Instalar Node.js e npm (LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && apt-get install -y nodejs

# Configurar PHP para processamento de arquivos grandes
RUN echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/custom.ini

# Definir diretório de trabalho
WORKDIR /var/www/html

# Expor porta 8000
EXPOSE 8000

# Comando padrão
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"] 